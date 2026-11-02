import { flushPromises } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { useAuthStore } from '@/stores/auth';
import { DEFAULT_PREFERENCES, usePreferencesStore } from '@/stores/preferences';
import { useToastStore } from '@/stores/toast';
import { makeUser, mockFetch } from '../helpers/fetch';

function makePreference(overrides: Record<string, unknown> = {}) {
    return { ...DEFAULT_PREFERENCES, ...overrides };
}

function meEnvelope(preference?: unknown) {
    return { data: makeUser(preference === undefined ? {} : { preference }) };
}

describe('preferences store', () => {
    it('seeds from the /auth/me envelope without an extra request', async () => {
        const preferences = makePreference({ locale: 'en-US', speech_rate: 1.5 });
        const { fetchMock } = mockFetch({});
        useAuthStore().user = meEnvelope(preferences).data;

        const store = usePreferencesStore();
        const loaded = await store.ensureLoaded();

        expect(loaded).toMatchObject({ locale: 'en-US', speech_rate: 1.5 });
        expect(store.preference?.locale).toBe('en-US');
        expect(store.speechRate).toBe(1.5);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('fetches GET /preferences when the me envelope has no preference', async () => {
        mockFetch({ 'GET /api/v1/preferences': { body: { data: makePreference({ silence_timeout_ms: 800 }) } } });
        useAuthStore().user = meEnvelope().data;

        const store = usePreferencesStore();
        const loaded = await store.ensureLoaded();

        expect(loaded.silence_timeout_ms).toBe(800);
        expect(store.wakeWordEnabled).toBe(false);
        expect(store.silenceTimeoutMs).toBe(800);
    });

    it('shares one request between concurrent ensureLoaded calls', async () => {
        const { fetchMock } = mockFetch({ 'GET /api/v1/preferences': { body: { data: makePreference() } } });
        useAuthStore().user = meEnvelope().data;

        const store = usePreferencesStore();
        const [first, second] = await Promise.all([store.ensureLoaded(), store.ensureLoaded()]);

        expect(first.locale).toBe('pt-BR');
        expect(second.locale).toBe('pt-BR');
        expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/preferences'))).toHaveLength(1);
    });

    it('update PUTs the patch and applies the response', async () => {
        const { calls } = mockFetch({
            'PUT /api/v1/preferences': {
                body: { data: makePreference({ speech_rate: 1.5, voice_name: 'Google português do Brasil' }) },
            },
        });
        useAuthStore().user = meEnvelope(makePreference()).data;

        const store = usePreferencesStore();
        const updated = await store.update({ speech_rate: 1.5 });

        expect(updated.speech_rate).toBe(1.5);
        expect(store.preference?.voice_name).toBe('Google português do Brasil');

        const put = calls.find((call) => call.method === 'PUT' && call.url === '/api/v1/preferences');
        expect(JSON.parse(String(put?.init?.body))).toEqual({ speech_rate: 1.5 });
    });

    it('forwards the wake word consent flag in the payload', async () => {
        const { calls } = mockFetch({
            'PUT /api/v1/preferences': { body: { data: makePreference({ wake_word_enabled: true }) } },
        });
        useAuthStore().user = meEnvelope(makePreference()).data;

        const store = usePreferencesStore();
        await store.update({ wake_word_enabled: true, wake_word_consent: true });

        const put = calls.find((call) => call.method === 'PUT' && call.url === '/api/v1/preferences');
        expect(JSON.parse(String(put?.init?.body))).toEqual({ wake_word_enabled: true, wake_word_consent: true });
    });

    it('surfaces the consent requirement when the API rejects the wake word', async () => {
        mockFetch({
            'PUT /api/v1/preferences': {
                status: 422,
                body: { message: 'Validation failed', errors: { wake_word_consent: ['consent required'] } },
            },
        });
        useAuthStore().user = meEnvelope(makePreference()).data;

        const store = usePreferencesStore();

        await expect(store.update({ wake_word_enabled: true })).rejects.toThrow('HTTP 422');

        const toasts = useToastStore();
        expect(toasts.toasts).toHaveLength(1);
        expect(toasts.toasts[0]?.variant).toBe('warning');
    });

    it('falls back to defaults when the fetch fails', async () => {
        mockFetch({ 'GET /api/v1/preferences': { status: 500, body: {} } });
        useAuthStore().user = meEnvelope().data;

        const store = usePreferencesStore();
        const loaded = await store.ensureLoaded();

        expect(loaded).toEqual(DEFAULT_PREFERENCES);
        expect(store.preference).toBeNull();
        await flushPromises();
    });
});
