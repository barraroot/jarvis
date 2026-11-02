import { createMemoryHistory } from 'vue-router';
import { describe, expect, it } from 'vitest';
import { createAppRouter } from '@/router';
import { makeUser, mockFetch } from './helpers/fetch';

describe('router guard', () => {
    it('loads the session user once via /api/v1/auth/me and allows protected routes', async () => {
        const { calls } = mockFetch({ 'GET /api/v1/auth/me': { body: { data: makeUser() } } });
        const router = createAppRouter(createMemoryHistory());

        await router.push('/');
        await router.push('/activity');

        expect(router.currentRoute.value.name).toBe('activity');
        expect(calls.filter((call) => call.url === '/api/v1/auth/me')).toHaveLength(1);
    });

    it('sends guests to login keeping the intended path', async () => {
        mockFetch({ 'GET /api/v1/auth/me': { status: 401 } });
        const router = createAppRouter(createMemoryHistory());

        await router.push('/conversations/7');

        expect(router.currentRoute.value.name).toBe('login');
        expect(router.currentRoute.value.query.redirect).toBe('/conversations/7');
    });

    it('sends authenticated users away from login', async () => {
        mockFetch({ 'GET /api/v1/auth/me': { body: { data: makeUser() } } });
        const router = createAppRouter(createMemoryHistory());

        await router.push('/login');

        expect(router.currentRoute.value.name).toBe('hud');
    });

    it('exposes every SPA route by name', () => {
        const router = createAppRouter(createMemoryHistory());

        for (const name of ['login', 'hud', 'conversation', 'settings', 'memories', 'activity']) {
            expect(router.hasRoute(name)).toBe(true);
        }
    });
});
