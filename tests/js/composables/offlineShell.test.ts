import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory } from 'vue-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import App from '@/App.vue';
import { createAppRouter } from '@/router';
import { useAssistantStore } from '@/stores/assistant';
import { useAuthStore } from '@/stores/auth';
import { useConnectionStore } from '@/stores/connection';
import { useConversationsStore } from '@/stores/conversations';
import { useMessagesStore } from '@/stores/messages';
import { makeUser, mockFetch } from '../helpers/fetch';

/** Every request fails like a fetch without network (the service worker's NetworkOnly rejects). */
function goOffline(): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(async () => {
        throw new TypeError('Failed to fetch');
    });
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function mountApp(path: string) {
    const router = createAppRouter(createMemoryHistory());
    await router.push(path);
    await router.isReady();

    const wrapper = mount(App, { global: { plugins: [router] }, attachTo: document.body });
    await flushPromises();

    return { wrapper, router };
}

describe('offline PWA shell (US-2.5)', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('opens the HUD DISCONNECTED with the banner and sending disabled instead of the login page', async () => {
        goOffline();

        const { wrapper, router } = await mountApp('/');

        expect(router.currentRoute.value.name).toBe('hud');
        expect(useAuthStore().unreachable).toBe(true);
        expect(useAssistantStore().state).toBe('DISCONNECTED');
        expect(wrapper.find('[data-testid="hud-layout"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="disconnected-banner"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="layout-footer-controls"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="bottom-nav-microphone"]').attributes('disabled')).toBeDefined();
        await vi.waitFor(() => expect(wrapper.find('[data-testid="jarvis-core"]').exists()).toBe(true));
        expect(wrapper.findAll('[data-testid="core-disconnect"]').length).toBeGreaterThan(0);

        wrapper.unmount();
    });

    it('never simulates an answer: sending offline is rejected and nothing is added', async () => {
        const fetchMock = goOffline();

        const { wrapper } = await mountApp('/');
        const conversations = useConversationsStore();

        await expect(useMessagesStore().send('Olá JARVIS', 'text')).rejects.toThrow('offline');

        expect(conversations.messages).toHaveLength(0);
        expect(fetchMock.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'POST')).toBe(false);

        wrapper.unmount();
    });

    it('re-probes the session when the network returns and restores the HUD', async () => {
        goOffline();

        const { wrapper, router } = await mountApp('/');
        expect(useConnectionStore().isDisconnected).toBe(true);

        mockFetch({ 'GET /api/v1/auth/me': { body: { data: makeUser() } } });
        window.dispatchEvent(new Event('online'));
        await flushPromises();

        expect(useAuthStore().isAuthenticated).toBe(true);
        expect(useAuthStore().unreachable).toBe(false);
        expect(useConnectionStore().serverUnreachable).toBe(false);
        expect(router.currentRoute.value.name).toBe('hud');
        await nextTick();
        expect(wrapper.find('[data-testid="disconnected-banner"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('retries with backoff while the server stays unreachable', async () => {
        vi.useFakeTimers();
        const fetchMock = goOffline();

        const { wrapper } = await mountApp('/');
        const connection = useConnectionStore();
        const probes = () => fetchMock.mock.calls.filter(([url]) => url === '/api/v1/auth/me').length;

        expect(connection.nextRetryDelayMs).toBe(1000);
        const before = probes();

        await vi.advanceTimersByTimeAsync(1000);
        expect(probes()).toBe(before + 1);
        expect(connection.nextRetryDelayMs).toBe(2000);

        wrapper.unmount();
    });

    it('sends an expired session to login once the server answers 401', async () => {
        goOffline();

        const { wrapper, router } = await mountApp('/settings');
        expect(router.currentRoute.value.name).toBe('settings');

        mockFetch({ 'GET /api/v1/auth/me': { status: 401, body: { message: 'Unauthenticated.' } } });
        window.dispatchEvent(new Event('online'));
        await flushPromises();

        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'));
        expect(useConnectionStore().isDisconnected).toBe(false);

        wrapper.unmount();
    });
});

describe('reconnection restore through "connecting" (US-2.4)', () => {
    it('reloads the open conversation when the link returns via connecting', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/conversations/c1': {
                body: { data: { id: 'c1', title: 'Conversa' }, messages: [], next_cursor: null },
            },
        });
        const conversations = useConversationsStore();
        const connection = useConnectionStore();

        useMessagesStore();
        await conversations.open('c1');

        connection.markDisconnected();
        await nextTick();
        connection.state = 'connecting';
        await nextTick();
        connection.markConnected();
        await flushPromises();

        expect(calls.filter((call) => call.url === '/api/v1/conversations/c1')).toHaveLength(2);
    });
});
