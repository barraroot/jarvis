import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { createMemoryHistory } from 'vue-router';
import { describe, expect, it, vi } from 'vitest';
import App from '@/App.vue';
import { createAppRouter } from '@/router';
import { DEFAULT_PREFERENCES } from '@/stores/preferences';
import { useVoiceStore } from '@/stores/voice';
import { makeUser, mockFetch } from './helpers/fetch';
import { mockViewport } from './helpers/matchMedia';

/** Echo stays off unless a test turns it on; then the user channel is an in-memory fake. */
const echo = vi.hoisted(() => {
    const listeners = new Map<string, (payload: unknown) => void>();

    return {
        enabled: false,
        listeners,
        channel: {
            listen(event: string, callback: (payload: unknown) => void) {
                listeners.set(event, callback);

                return this;
            },
        },
        emit(event: string, payload: unknown) {
            listeners.get(`.${event}`)?.(payload);
        },
    };
});

vi.mock('@/lib/echo', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/echo')>()),
    isEchoConfigured: () => echo.enabled,
    getEcho: () => ({}),
    disposeEcho: () => undefined,
    leaveUserChannel: () => undefined,
    subscribeToUserChannel: () => echo.channel,
    createEchoTransport: () => ({ onStateChange: () => undefined, onActivity: () => undefined, connect: () => undefined, disconnect: () => undefined }),
}));

async function mountApp(path: string, options: { animate?: boolean } = {}) {
    const router = createAppRouter(createMemoryHistory());
    await router.push(path);
    await router.isReady();

    const wrapper = mount(App, {
        global: { plugins: [router], stubs: options.animate ? { transition: false } : {} },
        attachTo: document.body,
    });
    await flushPromises();

    return { wrapper, router };
}

describe('App smoke test', () => {
    it('redirects a protected route to login when there is no user', async () => {
        mockFetch({ 'GET /api/v1/auth/me': { status: 401, body: { message: 'Unauthenticated.' } } });

        const { wrapper, router } = await mountApp('/settings');

        expect(router.currentRoute.value.name).toBe('login');
        expect(router.currentRoute.value.query.redirect).toBe('/settings');
        await vi.waitFor(() => expect(wrapper.find('[data-testid="login-form"]').exists()).toBe(true));
        expect(wrapper.find('[data-testid="hud-layout"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('renders the HUD shell for an authenticated user', async () => {
        mockFetch({ 'GET /api/v1/auth/me': { body: { data: makeUser() } } });

        const { wrapper, router } = await mountApp('/');

        expect(router.currentRoute.value.name).toBe('hud');
        expect(wrapper.find('[data-testid="hud-layout"]').exists()).toBe(true);
        await vi.waitFor(() => expect(wrapper.find('[data-testid="conversation-view"]').exists()).toBe(true));
        expect(wrapper.get('[data-testid="conversation-greeting"]').text()).toContain('Saudações');

        wrapper.unmount();
    });

    it('wires the realtime voice instead of the browser STT/TTS', async () => {
        const speechSynthesis = { speak: vi.fn(), cancel: vi.fn(), getVoices: () => [], addEventListener: vi.fn(), removeEventListener: vi.fn() };
        vi.stubGlobal('speechSynthesis', speechSynthesis);
        mockFetch({
            'GET /api/v1/auth/me': { body: { data: makeUser({ preference: { ...DEFAULT_PREFERENCES, wake_word_enabled: true, wake_word_consent_valid: true } }) } },
        });

        const { wrapper } = await mountApp('/');

        // happy-dom has no WebRTC: voice is unavailable and the HUD is text-only (RF-08a).
        expect(useVoiceStore().unavailableReason).toBe('unsupported');
        expect(wrapper.get('[data-testid="footer-mute"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="composer"]').exists()).toBe(true);
        expect(speechSynthesis.speak).not.toHaveBeenCalled();

        wrapper.unmount();
    });

    it('sinks the current route and raises the next one in 350 ms (UI-24)', async () => {
        mockViewport(1280);
        mockFetch({ 'GET /api/v1/auth/me': { body: { data: makeUser() } } });

        const { wrapper, router } = await mountApp('/settings', { animate: true });
        const view = wrapper.get('[data-testid="route-view"]');

        expect(view.attributes('data-motion')).toBe('full');

        await router.push('/memories');
        await nextTick();

        const [leaving, entering] = Array.from(view.element.children) as [Element, Element];
        expect(leaving.className).toEqual(expect.stringContaining('duration-[350ms]'));
        expect(leaving.className).toEqual(expect.stringContaining('absolute'));
        expect(entering.className).toEqual(expect.stringContaining('duration-[350ms]'));
        expect(entering.className).toEqual(expect.stringContaining('translate-y-4'));

        // The leaving screen sinks (scale .98 + blur) from the next frame on.
        const source = readFileSync(join(process.cwd(), 'resources/js/App.vue'), 'utf8');
        expect(source).toMatch(/leaveToClass: '[^']*scale-\[\.98\][^']*blur-sm/);

        wrapper.unmount();
    });

    it('swaps routes at once under reduced motion (RNF-01)', async () => {
        mockViewport(1280, { reducedMotion: true });
        mockFetch({ 'GET /api/v1/auth/me': { body: { data: makeUser() } } });

        const { wrapper, router } = await mountApp('/settings', { animate: true });
        const view = wrapper.get('[data-testid="route-view"]');

        expect(view.attributes('data-motion')).toBe('reduced');

        await router.push('/memories');
        await nextTick();

        expect(router.currentRoute.value.name).toBe('memories');
        expect(view.findAll('.duration-\\[350ms\\]')).toHaveLength(0);
        expect(view.element.children).toHaveLength(1);

        wrapper.unmount();
    });

    it('CT-10 on the user channel updates the WhatsApp panel on the settings page (UI-03)', async () => {
        echo.enabled = true;
        echo.listeners.clear();
        mockViewport(1280);
        const { fetchMock } = mockFetch({
            'GET /api/v1/auth/me': { body: { data: makeUser() } },
            'GET /api/v1/connectors': { body: { data: [] } },
            'GET /api/v1/whatsapp/session': {
                body: {
                    data: {
                        id: 7,
                        status: 'pending',
                        suspended: false,
                        phone_masked: null,
                        last_error: null,
                        last_checked_at: null,
                        connected_at: null,
                        consent: { version: 1, scopes: ['leitura', 'envio'], valid: true },
                        current_consent_version: 1,
                        usage_today: { sends: 0, cap: 100 },
                    },
                },
            },
            'GET /api/v1/whatsapp/schedules': { body: { data: [] } },
        });

        try {
            const { wrapper } = await mountApp('/settings');
            await vi.waitFor(() => expect(wrapper.get('[data-testid="whatsapp-status"]').text()).toBe('Pendente'));
            expect(echo.listeners.has('.whatsapp.session.status.changed')).toBe(true);
            const requests = fetchMock.mock.calls.length;

            echo.emit('whatsapp.session.status.changed', {
                session_id: 7,
                status: 'connected',
                previous_status: 'pending',
                suspended: false,
                error: null,
                at: '2026-11-07T12:00:00+00:00',
            });
            await nextTick();

            expect(wrapper.get('[data-testid="whatsapp-status"]').text()).toBe('Conectado');
            expect(wrapper.find('[data-testid="whatsapp-pairing"]').exists()).toBe(false);
            expect(fetchMock.mock.calls.length).toBe(requests);

            wrapper.unmount();
        } finally {
            echo.enabled = false;
        }
    });

    it('no source imports the removed browser STT/TTS composables', () => {
        const root = join(process.cwd(), 'resources/js');
        const offenders = sourceFiles(root).filter((file) => /useVoiceInput|useSpeechOutput|speechSynthesis\.speak/.test(readFileSync(file, 'utf8')));

        expect(offenders.map((file) => relative(root, file))).toEqual([]);
    });
});

function sourceFiles(directory: string): string[] {
    return readdirSync(directory).flatMap((entry) => {
        const path = join(directory, entry);

        return statSync(path).isDirectory() ? sourceFiles(path) : /\.(ts|vue)$/.test(path) ? [path] : [];
    });
}
