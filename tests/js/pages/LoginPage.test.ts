import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { resetCsrfCookie } from '@/lib/http';
import LoginPage from '@/pages/LoginPage.vue';
import { createAppRouter } from '@/router';
import { useAuthStore } from '@/stores/auth';
import { makeUser, mockFetch, type MockRoutes } from '../helpers/fetch';

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
        expect(wrapper.html().toLowerCase()).not.toMatch(/regist|cadastr|sign ?up/);
        expect(wrapper.findAll('a')).toHaveLength(0);
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

    it('redirects an already authenticated user away from /login', async () => {
        mockFetch({ 'GET /api/v1/auth/me': { body: { data: makeUser() } } });
        const router = createAppRouter(createMemoryHistory());

        await router.push('/login');

        expect(router.currentRoute.value.fullPath).toBe('/');
    });
});
