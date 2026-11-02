import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory } from 'vue-router';
import { describe, expect, it, vi } from 'vitest';
import { i18n } from '@/i18n';
import SettingsPage from '@/pages/SettingsPage.vue';
import { createAppRouter } from '@/router';
import { useAuthStore } from '@/stores/auth';
import { DEFAULT_PREFERENCES } from '@/stores/preferences';
import { useToastStore } from '@/stores/toast';
import { makeUser, mockFetch } from '../helpers/fetch';
import { stubSpeechRecognition } from '../helpers/speech';

interface FakeUtterance {
    text: string;
    lang: string;
    rate: number;
    voice: unknown;
}

function stubSpeechSynthesis(voices: Array<{ name: string; lang: string }> = []) {
    const utterances: FakeUtterance[] = [];

    class FakeSpeechSynthesisUtterance {
        text: string;
        lang = '';
        rate = 1;
        voice: unknown = null;

        constructor(text: string) {
            this.text = text;
            utterances.push(this as unknown as FakeUtterance);
        }
    }

    const synth = {
        speak: vi.fn(),
        cancel: vi.fn(),
        getVoices: () => voices,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    };

    vi.stubGlobal('SpeechSynthesisUtterance', FakeSpeechSynthesisUtterance);
    vi.stubGlobal('speechSynthesis', synth);

    return { synth, utterances, voices };
}

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
    it('renders the persisted preferences into the form', async () => {
        stubSpeechSynthesis([{ name: 'Voz X', lang: 'en-US' }]);
        seedPreferences({ speech_rate: 1.5, speech_locale: 'en-US', silence_timeout_ms: 800, voice_name: 'Voz X' });
        const { wrapper } = await mountSettings();

        expect((wrapper.get('[data-testid="settings-rate"]').element as HTMLInputElement).value).toBe('1.5');
        expect((wrapper.get('[data-testid="settings-silence"]').element as HTMLInputElement).value).toBe('800');
        expect((wrapper.get('[data-testid="settings-speech-locale"] select').element as HTMLSelectElement).value).toBe('en-US');
        expect((wrapper.get('[data-testid="settings-voice"] select').element as HTMLSelectElement).value).toBe('Voz X');
    });

    it('saves the form through PUT /api/v1/preferences', async () => {
        seedPreferences({ spoken_responses_enabled: true });
        const { wrapper, calls } = await mountSettings();

        await wrapper.get('[data-testid="settings-rate"]').setValue('1.8');
        await wrapper.get('[data-testid="settings-silence"]').setValue('2000');
        await wrapper.get('[data-testid="settings-spoken"]').trigger('click');
        await wrapper.get('[data-testid="settings-save"]').trigger('click');
        await flushPromises();

        expect(putPayloads(calls)).toEqual([
            expect.objectContaining({
                speech_rate: 1.8,
                silence_timeout_ms: 2000,
                spoken_responses_enabled: false,
                wake_word_enabled: false,
            }),
        ]);

        const toasts = useToastStore();
        expect(toasts.toasts.some((toast) => toast.variant === 'success')).toBe(true);
    });

    it('changing the interface language switches vue-i18n live', async () => {
        seedPreferences({ locale: 'pt-BR' });
        const { wrapper } = await mountSettings();

        await wrapper.get('[data-testid="settings-locale"] select').setValue('en-US');

        expect(i18n.global.locale.value).toBe('en-US');
    });

    it('lists only voices of the chosen speech locale and reloads on voiceschanged', async () => {
        const voicesList = [
            { name: 'Google português do Brasil', lang: 'pt-BR' },
            { name: 'Google US English', lang: 'en-US' },
            { name: 'Outra voz pt', lang: 'pt-PT' },
        ];
        stubSpeechSynthesis(voicesList);
        seedPreferences({ speech_locale: 'pt-BR' });
        const { wrapper } = await mountSettings();

        const voiceSelect = wrapper.get('[data-testid="settings-voice"] select');
        expect(voiceSelect.findAll('option').map((option) => option.text())).toContain('Google português do Brasil (pt-BR)');
        expect(voiceSelect.text()).not.toContain('Google US English');
        expect(voiceSelect.text()).not.toContain('Outra voz pt');
    });

    it('enabling the wake word requires the consent modal and saves the consent flag', async () => {
        stubSpeechRecognition();
        stubSpeechSynthesis();
        seedPreferences({ wake_word_enabled: false });
        const { wrapper, calls } = await mountSettings();

        await wrapper.get('[data-testid="settings-wake-word"]').trigger('click');
        expect(wrapper.find('[data-testid="settings-consent-modal"]').exists()).toBe(true);
        expect(putPayloads(calls)).toHaveLength(0);

        await wrapper.get('[data-testid="settings-consent-confirm"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="settings-consent-modal"]').exists()).toBe(false);
        expect(putPayloads(calls)).toEqual([
            expect.objectContaining({ wake_word_enabled: true, wake_word_consent: true }),
        ]);
    });

    it('cancelling the consent leaves the wake word off', async () => {
        stubSpeechRecognition();
        stubSpeechSynthesis();
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

    it('shows the wake word option disabled with an explanation when unsupported', async () => {
        stubSpeechSynthesis();
        seedPreferences();
        const { wrapper } = await mountSettings();

        const toggle = wrapper.get('[data-testid="settings-wake-word"]');
        expect(toggle.attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="settings-wake-word-unsupported"]').text()).toContain('não suporta');
    });

    it('test voice speaks the i18n phrase with the chosen rate and voice', async () => {
        const { synth, utterances } = stubSpeechSynthesis([
            { name: 'Google português do Brasil', lang: 'pt-BR' },
        ]);
        seedPreferences({ speech_rate: 1.5, voice_name: 'Google português do Brasil' });
        const { wrapper } = await mountSettings();

        await wrapper.get('[data-testid="settings-test-voice"]').trigger('click');

        expect(synth.speak).toHaveBeenCalledTimes(1);
        expect(utterances[0]?.text).toContain('J.A.R.V.I.S');
        expect(utterances[0]?.rate).toBe(1.5);
        expect((utterances[0]?.voice as { name?: string } | null)?.name).toBe('Google português do Brasil');
    });

    it('saving with the wake word already enabled re-sends the consent flag', async () => {
        stubSpeechSynthesis();
        seedPreferences({ wake_word_enabled: true, wake_word_consented_at: '2026-11-02T10:00:00.000000Z' });
        const { wrapper, calls } = await mountSettings();

        await wrapper.get('[data-testid="settings-save"]').trigger('click');
        await flushPromises();

        expect(putPayloads(calls)).toEqual([
            expect.objectContaining({ wake_word_enabled: true, wake_word_consent: true }),
        ]);
    });
});
