import { config } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, vi } from 'vitest';
import { i18n } from '@/i18n';

beforeEach(() => {
    const pinia = createPinia();

    setActivePinia(pinia);
    config.global.plugins = [i18n, pinia];
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
    localStorage.clear();
});
