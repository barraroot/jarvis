import { createMemoryHistory } from 'vue-router';
import { beforeEach, describe, expect, it } from 'vitest';
import { HttpError, http, resetCsrfCookie } from '@/lib/http';
import { createAppRouter } from '@/router';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import { makeUser, mockFetch } from '../helpers/fetch';

describe('http client', () => {
    beforeEach(() => {
        resetCsrfCookie();
    });

    it('fetches the CSRF cookie only once and forwards the XSRF token', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D%3D; path=/';
        const { calls } = mockFetch({
            'GET /sanctum/csrf-cookie': { status: 204 },
            'POST /api/v1/things': { status: 201, body: { ok: true } },
        });

        await http.post('/api/v1/things', { a: 1 });
        await http.post('/api/v1/things', { a: 2 });

        expect(calls.filter((call) => call.url === '/sanctum/csrf-cookie')).toHaveLength(1);
        const headers = calls[1]?.init?.headers as Record<string, string>;
        expect(headers['X-XSRF-TOKEN']).toBe('abc==');
        expect(headers.Accept).toBe('application/json');
    });

    it('does not fetch the CSRF cookie for GET requests', async () => {
        const { calls } = mockFetch({ 'GET /api/v1/things': { body: { data: [] } } });

        await expect(http.get('/api/v1/things')).resolves.toEqual({ data: [] });
        expect(calls).toHaveLength(1);
    });

    it('retries once with a fresh CSRF cookie after 419', async () => {
        let attempts = 0;
        const { calls } = mockFetch({
            'GET /sanctum/csrf-cookie': { status: 204 },
            'POST /api/v1/things': () => ({ status: attempts++ === 0 ? 419 : 200, body: { ok: true } }),
        });

        await expect(http.post('/api/v1/things')).resolves.toEqual({ ok: true });
        expect(calls.filter((call) => call.url === '/sanctum/csrf-cookie')).toHaveLength(2);
    });

    it('401 clears the auth store and redirects to login', async () => {
        let authenticated = true;
        mockFetch({
            'GET /api/v1/auth/me': () => (authenticated ? { body: { data: makeUser() } } : { status: 401 }),
            'GET /api/v1/things': { status: 401, body: { message: 'Unauthenticated.' } },
        });
        const router = createAppRouter(createMemoryHistory());
        const auth = useAuthStore();
        await router.push('/memories');
        authenticated = false;

        await expect(http.get('/api/v1/things')).rejects.toBeInstanceOf(HttpError);

        expect(auth.user).toBeNull();
        expect(router.currentRoute.value.name).toBe('login');
        expect(router.currentRoute.value.query.redirect).toBe('/memories');
    });

    it('429 emits a toast using Retry-After', async () => {
        mockFetch({ 'GET /api/v1/things': { status: 429, headers: { 'Retry-After': '42' } } });
        const toasts = useToastStore();

        const error = await http.get('/api/v1/things').catch((caught: unknown) => caught);

        expect(error).toBeInstanceOf(HttpError);
        expect((error as HttpError).retryAfter).toBe(42);
        expect(toasts.toasts).toHaveLength(1);
        expect(toasts.toasts[0]?.message).toContain('42');
        expect(toasts.toasts[0]?.variant).toBe('warning');
    });
});
