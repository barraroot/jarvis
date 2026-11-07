import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory } from 'vue-router';
import App from '@/App.vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { resetCsrfCookie } from '@/lib/http';
import LoginPage from '@/pages/LoginPage.vue';
import { createAppRouter } from '@/router';
import { useAuthStore } from '@/stores/auth';
import { makeUser, mockFetch, type MockRoutes } from '../helpers/fetch';
import { mockViewport } from '../helpers/matchMedia';

async function mountLogin(routes: MockRoutes, path = '/login') {
    const fetch = mockFetch({
        'GET /api/v1/auth/me': { status: 401 },
        'GET /sanctum/csrf-cookie': { status: 204 },
        ...routes,
    });
    const router = createAppRouter(createMemoryHistory());
    await router.push(path);

    const wrapper = mount(LoginPage, { global: { plugins: [router] } });

    return { wrapper, router, ...fetch };
}

/** Boot requests never answer, so the boot stays on screen while the test inspects it. */
function hangBootRequests(): void {
    const fetchMock = vi.mocked(fetch);
    const original = fetchMock.getMockImplementation()!;

    fetchMock.mockImplementation((input, init) =>
        /\/api\/v1\/(memories|agents|connectors|preferences)/.test(String(input))
            ? new Promise<Response>(() => undefined)
            : original(input, init),
    );
}

async function fillAndSubmit(wrapper: Awaited<ReturnType<typeof mountLogin>>['wrapper']) {
    await wrapper.get('#login-email').setValue('tony@example.com');
    await wrapper.get('#login-password').setValue('secret');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
}

describe('LoginPage', () => {
    beforeEach(() => {
        resetCsrfCookie();
    });

    it('renders the core placeholder, e-mail/password fields and no registration link', async () => {
        const { wrapper } = await mountLogin({});

        expect(wrapper.find('[data-testid="jarvis-core"]').exists()).toBe(true);
        expect(wrapper.get('#login-email').attributes('type')).toBe('email');
        expect(wrapper.get('#login-password').attributes('type')).toBe('password');
        expect(wrapper.get('button[type="submit"]').text()).toBe('Entrar');
        // The only mention of registration is the notice that there is none (UI-14).
        const notice = wrapper.get('[data-testid="login-no-registration"]').text();
        expect(notice).toBe('Sem auto-registro. O acesso é concedido pelo administrador do sistema.');
        expect(wrapper.text().replace(notice, '').toLowerCase()).not.toMatch(/regist|cadastr|sign ?up/);
        expect(wrapper.findAll('a')).toHaveLength(0);
    });

    it('renders the Abissal login: sleeping core, logo, tagline and the restricted access card (UI-14)', async () => {
        const { wrapper } = await mountLogin({});

        expect(wrapper.get('[data-testid="login-page"]').classes()).toContain('abyss-bg');
        expect(wrapper.get('[data-testid="jarvis-core"]').attributes('data-frame')).toBe('SLEEPING');
        expect(wrapper.text()).toContain('J.A.R.V.I.S');
        expect(wrapper.text()).toContain('Personal AI Operating System');
        expect(wrapper.get('[data-testid="login-title"]').text()).toBe('Acesso restrito · Identifique-se');
        expect(wrapper.get('[data-testid="login-form"]').classes()).toEqual(expect.arrayContaining(['glass', 'chamfer-md']));
        expect(wrapper.get('#login-email').classes()).toContain('min-h-11');
        expect(wrapper.get('#login-password').classes()).toContain('min-h-11');
        expect(wrapper.find('[data-testid="boot-sequence"]').exists()).toBe(false);
    });

    it('logs in and redirects to the HUD', async () => {
        const user = makeUser();
        const { wrapper, router, calls } = await mountLogin({ 'POST /api/v1/auth/login': { body: { data: user } } });

        await fillAndSubmit(wrapper);

        expect(useAuthStore().user).toEqual(user);
        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('hud'));
        const loginCall = calls.find((call) => call.url === '/api/v1/auth/login');
        expect(JSON.parse(String(loginCall?.init?.body))).toEqual({
            email: 'tony@example.com',
            password: 'secret',
            remember: false,
        });
    });

    it('honours a safe redirect query after login', async () => {
        const { wrapper, router } = await mountLogin(
            { 'POST /api/v1/auth/login': { body: { data: makeUser() } } },
            '/login?redirect=/settings',
        );

        await fillAndSubmit(wrapper);

        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('settings'));
    });

    it('shows 422 errors in the form', async () => {
        const { wrapper, router } = await mountLogin({
            'POST /api/v1/auth/login': {
                status: 422,
                body: { message: 'invalid', errors: { email: ['Credenciais inválidas.'] } },
            },
        });

        await fillAndSubmit(wrapper);

        expect(wrapper.text()).toContain('Credenciais inválidas.');
        expect(wrapper.get('#login-email').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('[data-testid="login-error"]').text()).toBe('Credenciais não reconhecidas. Tente de novo.');
        expect(wrapper.find('[data-testid="boot-sequence"]').exists()).toBe(false);
        expect(router.currentRoute.value.name).toBe('login');
    });

    it('shows a countdown on 429 and blocks submission until it ends', async () => {
        vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] });
        const { wrapper, calls } = await mountLogin({
            'POST /api/v1/auth/login': { status: 429, headers: { 'Retry-After': '3' }, body: { message: 'Too Many Attempts.' } },
        });

        await fillAndSubmit(wrapper);

        expect(wrapper.get('[data-testid="login-throttled"]').text()).toContain('3 s');
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();

        vi.advanceTimersByTime(1000);
        await flushPromises();
        expect(wrapper.get('[data-testid="login-throttled"]').text()).toContain('2 s');

        await wrapper.get('form').trigger('submit');
        expect(calls.filter((call) => call.url === '/api/v1/auth/login')).toHaveLength(1);

        vi.advanceTimersByTime(2000);
        await flushPromises();
        expect(wrapper.find('[data-testid="login-throttled"]').exists()).toBe(false);
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined();
    });

    it('shows the server message for other failures (e.g. deactivated account)', async () => {
        const { wrapper } = await mountLogin({
            'POST /api/v1/auth/login': { status: 403, body: { message: 'Sua conta está desativada.' } },
        });

        await fillAndSubmit(wrapper);

        expect(wrapper.get('[data-testid="login-error"]').text()).toBe('Sua conta está desativada.');
    });

    it('runs the boot after login and navigates only once the four items settled', async () => {
        mockViewport(1280);
        let releaseMemories: (() => void) | null = null;
        const { wrapper, router } = await mountLogin({
            'POST /api/v1/auth/login': { body: { data: makeUser() } },
            'GET /api/v1/agents': { status: 500, body: { message: 'Server Error' } },
            'GET /api/v1/connectors': { body: { data: [] } },
            'GET /api/v1/preferences': { body: { data: { wake_word_enabled: true } } },
        });
        const fetchMock = vi.mocked(fetch);
        const original = fetchMock.getMockImplementation()!;
        fetchMock.mockImplementation((input, init) => {
            if (input === '/api/v1/memories?page=1') {
                return new Promise((resolve) => {
                    releaseMemories = () =>
                        resolve(new Response(JSON.stringify({ summary: { confirmed: 4, hypotheses: 1 } }), { status: 200 }));
                });
            }

            return original(input, init);
        });

        await fillAndSubmit(wrapper);

        const boot = wrapper.get('[data-testid="boot-sequence"]');
        expect(boot.text()).toContain('INICIALIZANDO SISTEMAS…');
        expect(boot.get('[data-testid="boot-item-agents"]').attributes('data-status')).toBe('error');
        expect(boot.get('[data-testid="boot-item-agents"] [data-testid="boot-item-value"]').classes()).toContain('text-danger-400');
        expect(boot.get('[data-testid="boot-item-voice"]').text()).toContain('Palavra de ativação ligada');
        expect(boot.get('[data-testid="boot-item-memory"]').attributes('data-status')).toBe('pending');
        expect(router.currentRoute.value.name).toBe('login');

        releaseMemories!();
        await flushPromises();

        expect(wrapper.get('[data-testid="boot-item-memory"]').text()).toContain('4 confirmadas · 1 hipóteses');
        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('hud'));
    });

    it('navigates without waiting when a boot request hangs past 5 s', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        const { wrapper, router } = await mountLogin({
            'POST /api/v1/auth/login': { body: { data: makeUser() } },
            'GET /api/v1/memories?page=1': { body: { summary: { confirmed: 0, hypotheses: 0 } } },
            'GET /api/v1/agents': { body: { data: [] } },
            'GET /api/v1/preferences': { body: { data: { wake_word_enabled: false } } },
        });
        const fetchMock = vi.mocked(fetch);
        const original = fetchMock.getMockImplementation()!;
        fetchMock.mockImplementation((input, init) =>
            input === '/api/v1/connectors' ? new Promise<Response>(() => undefined) : original(input, init),
        );

        await fillAndSubmit(wrapper);
        expect(wrapper.get('[data-testid="boot-item-connectors"]').attributes('data-status')).toBe('pending');
        expect(router.currentRoute.value.name).toBe('login');

        vi.advanceTimersByTime(5000);
        await flushPromises();

        expect(wrapper.get('[data-testid="boot-item-connectors"]').attributes('data-status')).toBe('error');
        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('hud'));
        vi.useRealTimers();
    });

    it('animates the boot (rings, nucleus, sinking card) and drops it under reduced motion', async () => {
        mockViewport(1280);
        const animated = await mountLogin({ 'POST /api/v1/auth/login': { body: { data: makeUser() } } });
        hangBootRequests();

        await fillAndSubmit(animated.wrapper);

        const rings = animated.wrapper.findAll('[data-testid="boot-ring"]');
        expect(rings).toHaveLength(3);
        expect(rings.map((ring) => ring.attributes('style'))).toEqual([
            expect.stringContaining('boot-ignite 500ms'),
            expect.stringContaining('boot-ignite 500ms'),
            expect.stringContaining('boot-ignite 500ms'),
        ]);
        expect(rings[2]!.attributes('style')).toContain('1100ms');
        expect(animated.wrapper.get('[data-testid="boot-nucleus"]').attributes('style')).toContain('boot-wake 800ms');
        expect(animated.wrapper.get('[data-testid="login-form"]').attributes('style')).toContain('boot-sink');
        animated.wrapper.unmount();

        mockViewport(1280, { reducedMotion: true });
        const reduced = await mountLogin({ 'POST /api/v1/auth/login': { body: { data: makeUser() } } });
        hangBootRequests();

        await fillAndSubmit(reduced.wrapper);

        const boot = reduced.wrapper.get('[data-testid="boot-sequence"]');
        expect(boot.attributes('data-motion')).toBe('reduced');
        expect(boot.html()).not.toMatch(/boot-(ignite|wake|sink)|--animated/);
        expect(reduced.wrapper.find('[data-testid="login-form"]').exists()).toBe(false);
    });

    it('never shows the boot when the session is restored through /auth/me', async () => {
        const { calls } = mockFetch({ 'GET /api/v1/auth/me': { body: { data: makeUser() } } });
        const router = createAppRouter(createMemoryHistory());
        await router.push('/');
        await router.isReady();

        const wrapper = mount(App, { global: { plugins: [router] }, attachTo: document.body });
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('hud');
        expect(wrapper.find('[data-testid="boot-sequence"]').exists()).toBe(false);
        expect(calls.some((call) => call.url === '/api/v1/agents')).toBe(false);

        wrapper.unmount();
    });

    it('redirects an already authenticated user away from /login', async () => {
        mockFetch({ 'GET /api/v1/auth/me': { body: { data: makeUser() } } });
        const router = createAppRouter(createMemoryHistory());

        await router.push('/login');

        expect(router.currentRoute.value.fullPath).toBe('/');
    });
});
