import { vi } from 'vitest';

export interface MockResponse {
    status?: number;
    body?: unknown;
    headers?: Record<string, string>;
}

export type MockRoutes = Record<string, MockResponse | ((init: RequestInit | undefined) => MockResponse)>;

export interface RecordedCall {
    method: string;
    url: string;
    init: RequestInit | undefined;
}

/**
 * Stubs global fetch with a `"METHOD /path"` routing table and records every call.
 */
export function mockFetch(routes: MockRoutes) {
    const calls: RecordedCall[] = [];

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.pathname : input.url;
        const method = (init?.method ?? 'GET').toUpperCase();

        calls.push({ method, url, init });

        const route = routes[`${method} ${url}`];

        if (route === undefined) {
            return new Response(JSON.stringify({ message: 'Not Found' }), { status: 404 });
        }

        const { status = 200, body, headers = {} } = typeof route === 'function' ? route(init) : route;

        return new Response(body === undefined ? null : JSON.stringify(body), { status, headers });
    });

    vi.stubGlobal('fetch', fetchMock);

    return { fetchMock, calls };
}

export function makeUser(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        name: 'Tony Stark',
        email: 'tony@example.com',
        is_admin: false,
        is_active: true,
        last_login_at: null,
        ...overrides,
    };
}
