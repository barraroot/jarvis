import { config } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, vi } from 'vitest';
import { i18n } from '@/i18n';

beforeEach(() => {
    const pinia = createPinia();

    setActivePinia(pinia);
    config.global.plugins = [i18n, pinia];
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    // Default fetch answers 404 so tests that mount chrome fetching on setup never hit the network.
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => new Response(JSON.stringify({ message: 'Not Found' }), { status: 404 })),
    );
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
    localStorage.clear();
});
