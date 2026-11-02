import { createMemoryHistory } from 'vue-router';
import { describe, expect, it } from 'vitest';
import { createAppRouter } from '@/router';
import { useAuthStore } from '@/stores/auth';
import { makeUser, mockFetch } from '../helpers/fetch';

describe('auth store', () => {
    it('login stores the user returned by the API after fetching the CSRF cookie', async () => {
        const user = makeUser();
        const { calls } = mockFetch({
            'GET /sanctum/csrf-cookie': { status: 204 },
            'POST /api/v1/auth/login': { body: { data: user } },
        });
        const auth = useAuthStore();

        await auth.login({ email: 'tony@example.com', password: 'secret' });

        expect(auth.user).toEqual(user);
        expect(auth.isAuthenticated).toBe(true);
        expect(calls.map((call) => `${call.method} ${call.url}`)).toEqual([
            'GET /sanctum/csrf-cookie',
            'POST /api/v1/auth/login',
        ]);
        expect(calls[1]?.init?.credentials).toBe('include');
        expect(JSON.parse(String(calls[1]?.init?.body))).toEqual({ email: 'tony@example.com', password: 'secret' });
    });

    it('login propagates validation errors and keeps the user empty', async () => {
        mockFetch({
            'GET /sanctum/csrf-cookie': { status: 204 },
            'POST /api/v1/auth/login': {
                status: 422,
                body: { message: 'invalid', errors: { email: ['Credenciais inválidas.'] } },
            },
        });
        const auth = useAuthStore();

        await expect(auth.login({ email: 'x@example.com', password: 'bad' })).rejects.toMatchObject({
            status: 422,
            validationErrors: { email: ['Credenciais inválidas.'] },
        });
        expect(auth.user).toBeNull();
    });

    it('logout clears the user and redirects to login', async () => {
        mockFetch({
            'GET /sanctum/csrf-cookie': { status: 204 },
            'GET /api/v1/auth/me': { body: { data: makeUser() } },
            'POST /api/v1/auth/logout': { status: 204 },
        });
        const router = createAppRouter(createMemoryHistory());
        const auth = useAuthStore();

        await router.push('/settings');
        expect(router.currentRoute.value.name).toBe('settings');
        expect(auth.isAuthenticated).toBe(true);

        await auth.logout();

        expect(auth.user).toBeNull();
        expect(router.currentRoute.value.name).toBe('login');
    });

    it('logout still clears local state when the API call fails', async () => {
        mockFetch({
            'GET /sanctum/csrf-cookie': { status: 204 },
            'POST /api/v1/auth/logout': { status: 500 },
        });
        const auth = useAuthStore();
        auth.user = makeUser();

        await auth.logout();

        expect(auth.user).toBeNull();
    });

    it('fetchMe resolves to null on 401 without throwing', async () => {
        mockFetch({ 'GET /api/v1/auth/me': { status: 401, body: { message: 'Unauthenticated.' } } });
        const auth = useAuthStore();

        await expect(auth.fetchMe()).resolves.toBeNull();
        expect(auth.loaded).toBe(true);
    });
});
