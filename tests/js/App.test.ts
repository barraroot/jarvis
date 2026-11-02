import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory } from 'vue-router';
import { describe, expect, it, vi } from 'vitest';
import App from '@/App.vue';
import { createAppRouter } from '@/router';
import { makeUser, mockFetch } from './helpers/fetch';

async function mountApp(path: string) {
    const router = createAppRouter(createMemoryHistory());
    await router.push(path);
    await router.isReady();

    const wrapper = mount(App, { global: { plugins: [router] }, attachTo: document.body });
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
        await vi.waitFor(() => expect(wrapper.get('[data-testid="layout-main"]').text()).toContain('Painel principal'));

        wrapper.unmount();
    });
});
