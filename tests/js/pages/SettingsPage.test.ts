import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory } from 'vue-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { i18n } from '@/i18n';
import SettingsPage from '@/pages/SettingsPage.vue';
import { createAppRouter } from '@/router';
import { useAuthStore } from '@/stores/auth';
import { DEFAULT_PREFERENCES } from '@/stores/preferences';
import { useToastStore } from '@/stores/toast';
import { makeUser, mockFetch, type MockRoutes } from '../helpers/fetch';
import { stubSpeechRecognition } from '../helpers/speech';

function seedPreferences(overrides: Record<string, unknown> = {}) {
    return useAuthStore().user = makeUser({ preference: { ...DEFAULT_PREFERENCES, ...overrides } });
}

async function mountSettings(routes: MockRoutes = {}) {
    const { calls } = mockFetch({
        'PUT /api/v1/preferences': { body: { data: { ...DEFAULT_PREFERENCES } } },
        ...routes,
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
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('renders the persisted languages and browser voice fields', async () => {
        seedPreferences({
            speech_locale: 'en-US',
            voice_name: 'Samantha',
            speech_rate: 1.2,
            spoken_responses_enabled: false,
            silence_timeout_ms: 2000,
        });
        const { wrapper } = await mountSettings();

        expect((wrapper.get('[data-testid="settings-speech-locale"] select').element as HTMLSelectElement).value).toBe('en-US');
        expect((wrapper.get('[data-testid="settings-voice"] select').element as HTMLSelectElement).value).toBe('Samantha');
        expect(wrapper.get('[data-testid="settings-voice"]').text()).toContain('Padrão do navegador');
        expect((wrapper.get('[data-testid="settings-rate"] input').element as HTMLInputElement).value).toBe('1.2');
        expect(wrapper.get('[data-testid="settings-rate-value"]').text()).toBe('1,2');
        expect(wrapper.get('[data-testid="settings-spoken"]').attributes('aria-checked')).toBe('false');
        expect((wrapper.get('[data-testid="settings-silence"] input').element as HTMLInputElement).value).toBe('2000');
        expect(wrapper.find('[data-testid="settings-test-voice"]').exists()).toBe(false);
    });

    it('lists the browser voices for the speech language next to the default', async () => {
        const voices = [
            { name: 'Luciana', lang: 'pt-BR' },
            { name: 'Samantha', lang: 'en-US' },
        ];
        vi.stubGlobal('speechSynthesis', Object.assign(new EventTarget(), { getVoices: () => voices }));
        seedPreferences({ speech_locale: 'pt-BR', voice_name: null });
        const { wrapper } = await mountSettings();

        const options = wrapper.findAll('[data-testid="settings-voice"] option').map((option) => option.text());
        expect(options).toContain('Padrão do navegador');
        expect(options).toContain('Luciana');
        expect(options).not.toContain('Samantha');
        expect((wrapper.get('[data-testid="settings-voice"] select').element as HTMLSelectElement).value).toBe('__browser_default__');
    });

    it('saves the form with the voice fields through PUT /api/v1/preferences', async () => {
        seedPreferences({ speech_locale: 'pt-BR', voice_name: 'Luciana', speech_rate: 1, spoken_responses_enabled: true, silence_timeout_ms: 1500 });
        const { wrapper, calls } = await mountSettings();

        await wrapper.get('[data-testid="settings-speech-locale"] select').setValue('en-US');
        await wrapper.get('[data-testid="settings-rate"] input').setValue('1.5');
        await wrapper.get('[data-testid="settings-silence"] input').setValue('3000');
        await wrapper.get('[data-testid="settings-spoken"]').trigger('click');
        await wrapper.get('[data-testid="settings-voice"] select').setValue('__browser_default__');
        await wrapper.get('[data-testid="settings-save"]').trigger('click');
        await flushPromises();

        const [payload] = putPayloads(calls);
        expect(payload).toEqual({
            locale: 'pt-BR',
            speech_locale: 'en-US',
            voice_name: null,
            speech_rate: 1.5,
            spoken_responses_enabled: false,
            silence_timeout_ms: 3000,
            wake_word_enabled: false,
        });
        expect(useToastStore().toasts.some((toast) => toast.variant === 'success')).toBe(true);
    });

    it('shows the four sections and keeps the #connectors anchor', async () => {
        seedPreferences();
        const { wrapper } = await mountSettings();

        const nav = wrapper.findAll('[data-testid="settings-nav"] a');
        expect(nav.map((link) => link.text())).toEqual(['Preferências', 'Voz', 'Conectores MCP', 'Instalar PWA']);
        expect(nav.map((link) => link.attributes('href'))).toEqual(['#preferences', '#voice', '#connectors', '#install']);

        for (const section of ['preferences', 'voice', 'connectors', 'install']) {
            expect(wrapper.find(`[data-testid="settings-section-${section}"]`).exists(), section).toBe(true);
        }
        expect(wrapper.find('#connectors').exists()).toBe(true);
    });

    it('mounts the WhatsApp panel and its schedules right after the connectors', async () => {
        seedPreferences();
        const { wrapper, calls } = await mountSettings({
            'GET /api/v1/connectors': { body: { data: [] } },
            'GET /api/v1/whatsapp/session': {
                body: {
                    data: {
                        id: 7,
                        status: 'connected',
                        suspended: false,
                        phone_masked: '+55 11 *****-4321',
                        last_error: null,
                        last_checked_at: null,
                        connected_at: '2026-11-07T12:00:00+00:00',
                        consent: { version: 1, scopes: ['leitura', 'envio'], valid: true },
                        current_consent_version: 1,
                        usage_today: { sends: 0, cap: 100 },
                    },
                },
            },
            'GET /api/v1/whatsapp/schedules': { body: { data: [] } },
        });

        const section = wrapper.get('[data-testid="settings-section-whatsapp"]');
        expect(section.get('[data-testid="whatsapp-panel"]').text()).toContain('WhatsApp');
        expect(section.get('[data-testid="whatsapp-status"]').text()).toBe('Conectado');
        expect(section.get('[data-testid="whatsapp-schedules"]').text()).toContain('Agendamentos do WhatsApp');
        expect(section.find('[data-testid="whatsapp-schedules-empty"]').exists()).toBe(true);

        const order = wrapper.findAll('[data-testid^="settings-section-"]').map((node) => node.attributes('data-testid'));
        expect(order.indexOf('settings-section-whatsapp')).toBe(order.indexOf('settings-section-connectors') + 1);

        expect(calls.some((call) => call.method === 'GET' && call.url === '/api/v1/whatsapp/session')).toBe(true);
        expect(calls.some((call) => call.method === 'GET' && call.url === '/api/v1/whatsapp/schedules')).toBe(true);
    });

    it('omits board-only fields without a real source', async () => {
        seedPreferences();
        const { wrapper } = await mountSettings();

        for (const text of ['Limite de custo', 'Modelo padrão', 'Janela de escuta', 'Ouvir amostra']) {
            expect(wrapper.text(), text).not.toContain(text);
        }
        expect(wrapper.find('[data-testid="settings-test-voice"]').exists()).toBe(false);
    });

    it('shows the connectors empty state whose action opens the form', async () => {
        seedPreferences();
        const { wrapper } = await mountSettings({ 'GET /api/v1/connectors': { body: { data: [] } } });

        const empty = wrapper.get('[data-testid="connectors-empty"]');
        expect(empty.get('[data-testid="empty-state-title"]').text()).toBe('Nenhum conector MCP');
        expect(wrapper.find('[data-testid="connectors-form"]').exists()).toBe(false);

        const add = wrapper.get('[data-testid="connectors-empty-add"]');
        expect(add.text()).toBe('Adicionar conector');
        await add.trigger('click');

        expect(wrapper.find('[data-testid="connectors-form"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="connectors-empty"]').exists()).toBe(false);
    });

    it('shows when the wake word consent was accepted', async () => {
        stubSpeechRecognition();
        seedPreferences({ wake_word_enabled: true, wake_word_consented_at: '2026-11-04T10:00:00.000000Z', wake_word_consent_valid: true });
        const { wrapper } = await mountSettings();

        expect(wrapper.get('[data-testid="settings-consented-at"]').text()).toMatch(/^Termo aceito em \d{2}\/\d{2}\/\d{4}/);
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
