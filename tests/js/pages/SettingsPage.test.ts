import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory } from 'vue-router';
import { describe, expect, it } from 'vitest';
import { i18n } from '@/i18n';
import SettingsPage from '@/pages/SettingsPage.vue';
import { createAppRouter } from '@/router';
import { useAuthStore } from '@/stores/auth';
import { DEFAULT_PREFERENCES } from '@/stores/preferences';
import { useToastStore } from '@/stores/toast';
import { makeUser, mockFetch } from '../helpers/fetch';
import { stubSpeechRecognition } from '../helpers/speech';

function seedPreferences(overrides: Record<string, unknown> = {}) {
    return useAuthStore().user = makeUser({ preference: { ...DEFAULT_PREFERENCES, ...overrides } });
}

async function mountSettings() {
    const { calls } = mockFetch({
        'PUT /api/v1/preferences': { body: { data: { ...DEFAULT_PREFERENCES } } },
    });
    const router = createAppRouter(createMemoryHistory());
    await router.push('/settings');

    const wrapper = mount(SettingsPage, { global: { plugins: [router] } });
    await flushPromises();

    return { wrapper, calls };
}

function putPayloads(calls: Array<{ method: string; url: string; init: RequestInit | undefined }>) {
    return calls
        .filter((call) => call.method === 'PUT' && call.url === '/api/v1/preferences')
        .map((call) => JSON.parse(String(call.init?.body)) as Record<string, unknown>);
}

describe('SettingsPage', () => {
    it('renders the persisted languages and hides the browser voice fields', async () => {
        seedPreferences({ speech_locale: 'en-US' });
        const { wrapper } = await mountSettings();

        expect((wrapper.get('[data-testid="settings-speech-locale"] select').element as HTMLSelectElement).value).toBe('en-US');
        for (const field of ['settings-voice', 'settings-rate', 'settings-silence', 'settings-spoken', 'settings-test-voice']) {
            expect(wrapper.find(`[data-testid="${field}"]`).exists(), field).toBe(false);
        }
    });

    it('saves the form through PUT /api/v1/preferences without browser voice fields', async () => {
        seedPreferences({ speech_locale: 'pt-BR' });
        const { wrapper, calls } = await mountSettings();

        await wrapper.get('[data-testid="settings-speech-locale"] select').setValue('en-US');
        await wrapper.get('[data-testid="settings-save"]').trigger('click');
        await flushPromises();

        const [payload] = putPayloads(calls);
        expect(payload).toEqual({ locale: 'pt-BR', speech_locale: 'en-US', wake_word_enabled: false });
        expect(useToastStore().toasts.some((toast) => toast.variant === 'success')).toBe(true);
    });

    it('changing the interface language switches vue-i18n live', async () => {
        seedPreferences({ locale: 'pt-BR' });
        const { wrapper } = await mountSettings();

        await wrapper.get('[data-testid="settings-locale"] select').setValue('en-US');

        expect(i18n.global.locale.value).toBe('en-US');
    });

    it('enabling the wake word shows the new consent text and saves the consent flag', async () => {
        stubSpeechRecognition();
        seedPreferences({ wake_word_enabled: false });
        const { wrapper, calls } = await mountSettings();

        await wrapper.get('[data-testid="settings-wake-word"]').trigger('click');
        expect(wrapper.find('[data-testid="settings-consent-modal"]').exists()).toBe(true);
        const body = wrapper.get('[data-testid="settings-consent-body"]').text();
        expect(body).toContain('detectada localmente, no navegador');
        expect(body).toContain('apenas durante a janela de escuta');
        expect(body).toContain('OpenAI');
        expect(putPayloads(calls)).toHaveLength(0);

        await wrapper.get('[data-testid="settings-consent-confirm"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="settings-consent-modal"]').exists()).toBe(false);
        expect(putPayloads(calls)).toEqual([expect.objectContaining({ wake_word_enabled: true, wake_word_consent: true })]);
    });

    it('cancelling the consent leaves the wake word off', async () => {
        stubSpeechRecognition();
        seedPreferences({ wake_word_enabled: false });
        const { wrapper, calls } = await mountSettings();

        await wrapper.get('[data-testid="settings-wake-word"]').trigger('click');
        await wrapper.get('[data-testid="settings-consent-cancel"]').trigger('click');
        await wrapper.get('[data-testid="settings-save"]').trigger('click');
        await flushPromises();

        const payload = putPayloads(calls)[0];
        expect(payload?.wake_word_enabled).toBe(false);
        expect(payload?.wake_word_consent).toBeUndefined();
    });

    it('asks for a new consent when wake_word_consent_valid is false (RF-22)', async () => {
        stubSpeechRecognition();
        seedPreferences({
            wake_word_enabled: false,
            wake_word_consented_at: '2026-10-01T10:00:00.000000Z',
            wake_word_consent_valid: false,
        });
        const { wrapper, calls } = await mountSettings();

        expect(wrapper.get('[data-testid="settings-reconsent"]').text()).toContain('aceite o novo texto');

        await wrapper.get('[data-testid="settings-reconsent-review"]').trigger('click');
        expect(wrapper.get('[data-testid="settings-consent-modal"]').text()).toContain('Novo termo de privacidade da voz');
        expect(wrapper.get('[data-testid="settings-consent-body"]').text()).toContain('janela de escuta');

        await wrapper.get('[data-testid="settings-consent-confirm"]').trigger('click');
        await flushPromises();

        expect(putPayloads(calls)).toEqual([expect.objectContaining({ wake_word_enabled: true, wake_word_consent: true })]);
        expect(wrapper.find('[data-testid="settings-reconsent"]').exists()).toBe(false);
    });

    it('does not show the reconsent notice for a valid consent', async () => {
        stubSpeechRecognition();
        seedPreferences({ wake_word_enabled: true, wake_word_consented_at: '2026-11-04T10:00:00.000000Z', wake_word_consent_valid: true });
        const { wrapper, calls } = await mountSettings();

        expect(wrapper.find('[data-testid="settings-reconsent"]').exists()).toBe(false);

        await wrapper.get('[data-testid="settings-save"]').trigger('click');
        await flushPromises();

        expect(putPayloads(calls)).toEqual([expect.objectContaining({ wake_word_enabled: true, wake_word_consent: true })]);
    });

    it('shows the wake word option disabled with an explanation when unsupported', async () => {
        seedPreferences();
        const { wrapper } = await mountSettings();

        const toggle = wrapper.get('[data-testid="settings-wake-word"]');
        expect(toggle.attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="settings-wake-word-unsupported"]').text()).toContain('não suporta');
    });
});
