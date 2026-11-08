import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import WhatsappPanel from '@/components/hud/WhatsappPanel.vue';
import { useWhatsappStore } from '@/stores/whatsapp';
import type { WhatsappSession } from '@/types/models';
import { mockFetch, type RecordedCall } from '../helpers/fetch';

const QR = 'data:image/png;base64,iVBORw0KGgo=';
const SCOPES = ['leitura', 'envio', 'grupos', 'midia'] as const;

function makeSession(overrides: Partial<WhatsappSession> = {}): WhatsappSession {
    return {
        id: 7,
        status: 'pending',
        suspended: false,
        phone_masked: null,
        last_error: null,
        last_checked_at: null,
        connected_at: null,
        consent: { version: 1, scopes: ['leitura', 'envio'], valid: true },
        current_consent_version: 1,
        usage_today: { sends: 0, cap: 100 },
        ...overrides,
    };
}

function find(calls: RecordedCall[], method: string, url: string): RecordedCall | undefined {
    return calls.find((call) => call.method === method && call.url === url);
}

function bodyOf(call: RecordedCall | undefined): unknown {
    return typeof call?.init?.body === 'string' ? JSON.parse(call.init.body) : call?.init?.body;
}

async function mountPanel(): Promise<VueWrapper> {
    const wrapper = mount(WhatsappPanel);

    await flushPromises();

    return wrapper;
}

describe('WhatsappPanel', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        localStorage.clear();
        sessionStorage.clear();
    });

    it('shows the consent text with 4 unchecked scopes and only enables accept with one checked (UI-01, CT-01)', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/whatsapp/session': { body: { data: null } },
            'POST /api/v1/whatsapp/consent': {
                body: { data: { version: 1, scopes: ['envio'], consented_at: '2026-11-07T12:00:00+00:00', valid: true } },
            },
        });

        const wrapper = await mountPanel();
        const consent = wrapper.get('[data-testid="whatsapp-consent"]');

        expect(consent.text()).toContain('Versão 1 do termo');
        expect(consent.text()).toContain('Leitura e armazenamento');
        expect(consent.text()).toContain('Envio sob aprovação');
        expect(consent.text()).toContain('Provedor de IA');
        expect(consent.text()).toContain('Freios');
        expect(consent.text()).toContain('Retenção');
        expect(consent.text()).toContain('Revogação');

        for (const scope of SCOPES) {
            expect(wrapper.get(`[data-testid="whatsapp-scope-${scope}"]`).attributes('aria-checked')).toBe('false');
        }

        const accept = wrapper.get('[data-testid="whatsapp-consent-accept"]');
        expect(accept.attributes('disabled')).toBeDefined();

        await accept.trigger('click');
        expect(find(calls, 'POST', '/api/v1/whatsapp/consent')).toBeUndefined();

        await wrapper.get('[data-testid="whatsapp-scope-envio"]').trigger('click');
        expect(accept.attributes('disabled')).toBeUndefined();

        await accept.trigger('click');
        await flushPromises();

        expect(bodyOf(find(calls, 'POST', '/api/v1/whatsapp/consent'))).toEqual({ version: 1, scopes: ['envio'] });
        expect(wrapper.find('[data-testid="whatsapp-consent"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="whatsapp-connect"]').text()).toBe('Conectar WhatsApp');

        wrapper.unmount();
    });

    it('asks for the new version when the accepted consent is outdated', async () => {
        mockFetch({
            'GET /api/v1/whatsapp/session': {
                body: { data: makeSession({ consent: { version: 1, scopes: ['leitura'], valid: false }, current_consent_version: 2 }) },
            },
        });

        const wrapper = await mountPanel();

        expect(wrapper.get('[data-testid="whatsapp-consent-version"]').text()).toBe('Versão 2 do termo');
        expect(wrapper.find('[data-testid="whatsapp-consent-outdated"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="whatsapp-scope-leitura"]').attributes('aria-checked')).toBe('false');
        expect(wrapper.find('[data-testid="whatsapp-pairing"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('creates the session with "Conectar WhatsApp" once consent is valid (CT-04)', async () => {
        const { calls } = mockFetch({ 'POST /api/v1/whatsapp/session': { status: 201, body: { data: makeSession() } } });
        const whatsapp = useWhatsappStore();
        whatsapp.consent = { version: 1, scopes: ['leitura', 'envio'], valid: true };
        whatsapp.loaded = true;

        const wrapper = await mountPanel();

        expect(find(calls, 'GET', '/api/v1/whatsapp/session')).toBeUndefined();
        expect(wrapper.get('[data-testid="whatsapp-consent-current"]').text()).toContain('Leitura, Envio');

        await wrapper.get('[data-testid="whatsapp-connect"]').trigger('click');
        await flushPromises();

        expect(find(calls, 'POST', '/api/v1/whatsapp/session')).toBeDefined();
        expect(wrapper.get('[data-testid="whatsapp-status"]').text()).toBe('Pendente');
        expect(wrapper.find('[data-testid="whatsapp-pairing"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('pairs by QR with "Gerar novo QR" and by phone with a monospace code (UI-02, CT-05, CT-06)', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/whatsapp/session': { body: { data: makeSession({ status: 'disconnected' }) } },
            'POST /api/v1/whatsapp/session/qr': { body: { data: { qr: QR } } },
            'POST /api/v1/whatsapp/session/pair-phone': { body: { data: { code: 'ABCD-1234' } } },
        });

        const wrapper = await mountPanel();

        expect(wrapper.get('[data-testid="whatsapp-status"]').text()).toBe('Desconectado');
        expect(wrapper.get('[data-testid="whatsapp-pair-qr"]').text()).toContain('Conectar por QR code');
        expect(wrapper.get('[data-testid="whatsapp-pair-phone"]').text()).toContain('Conectar por número');

        await wrapper.get('[data-testid="whatsapp-qr-request"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="whatsapp-qr-image"]').attributes('src')).toBe(QR);
        const refresh = wrapper.get('[data-testid="whatsapp-qr-refresh"]');
        expect(refresh.text()).toBe('Gerar novo QR');

        await refresh.trigger('click');
        await flushPromises();
        expect(calls.filter((call) => call.url === '/api/v1/whatsapp/session/qr')).toHaveLength(2);

        await wrapper.get('[data-testid="whatsapp-phone-input"] input').setValue('11-99');
        await wrapper.get('[data-testid="whatsapp-pair-phone"]').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[data-testid="whatsapp-pair-phone"]').text()).toContain('Informe o telefone com DDI e DDD');
        expect(find(calls, 'POST', '/api/v1/whatsapp/session/pair-phone')).toBeUndefined();

        await wrapper.get('[data-testid="whatsapp-phone-input"] input').setValue('5511999998888');
        await wrapper.get('[data-testid="whatsapp-pair-phone"]').trigger('submit');
        await flushPromises();

        expect(bodyOf(find(calls, 'POST', '/api/v1/whatsapp/session/pair-phone'))).toEqual({ phone: '5511999998888' });
        const code = wrapper.get('[data-testid="whatsapp-pairing-code"]');
        expect(code.text()).toBe('ABCD-1234');
        expect(code.classes()).toContain('font-mono');

        const stored = [
            ...Object.keys(localStorage).map((key) => localStorage.getItem(key)),
            ...Object.keys(sessionStorage).map((key) => sessionStorage.getItem(key)),
        ].join('\n');
        expect(stored).not.toContain(QR);
        expect(stored).not.toContain('ABCD-1234');

        wrapper.unmount();
    });

    it('shows the phone validation error returned by the server', async () => {
        mockFetch({
            'GET /api/v1/whatsapp/session': { body: { data: makeSession() } },
            'POST /api/v1/whatsapp/session/pair-phone': {
                status: 422,
                body: { message: 'Telefone inválido.', errors: { phone: ['Telefone inválido.'] } },
            },
        });

        const wrapper = await mountPanel();

        await wrapper.get('[data-testid="whatsapp-phone-input"] input').setValue('5511999998888');
        await wrapper.get('[data-testid="whatsapp-pair-phone"]').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[data-testid="whatsapp-pair-phone"]').text()).toContain('Telefone inválido.');
        expect(wrapper.find('[data-testid="whatsapp-pairing-code"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('updates the badge from CT-10 and hides the QR once connected (UI-03)', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/whatsapp/session': { body: { data: makeSession() } },
            'POST /api/v1/whatsapp/session/qr': { body: { data: { qr: QR } } },
        });

        const wrapper = await mountPanel();

        await wrapper.get('[data-testid="whatsapp-qr-request"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="whatsapp-qr-image"]').exists()).toBe(true);

        useWhatsappStore().applyStatusChange({
            session_id: 7,
            status: 'connected',
            previous_status: 'pending',
            suspended: false,
            error: null,
            at: '2026-11-07T12:00:00+00:00',
        });
        await flushPromises();

        expect(wrapper.get('[data-testid="whatsapp-status"]').text()).toBe('Conectado');
        expect(wrapper.find('[data-testid="whatsapp-qr-image"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="whatsapp-pairing"]').exists()).toBe(false);
        expect(calls.filter((call) => call.url === '/api/v1/whatsapp/session')).toHaveLength(1);

        wrapper.unmount();
    });

    it('does not offer pairing for a suspended session', async () => {
        mockFetch({ 'GET /api/v1/whatsapp/session': { body: { data: makeSession({ status: 'disconnected', suspended: true }) } } });

        const wrapper = await mountPanel();

        expect(wrapper.get('[data-testid="whatsapp-suspended"]').text()).toContain('Suspensa pelo administrador');
        expect(wrapper.find('[data-testid="whatsapp-pairing"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('shows the error message and retries with CT-04 (UI-06)', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/whatsapp/session': {
                body: { data: makeSession({ status: 'error', last_error: 'O serviço do WhatsApp não respondeu.' }) },
            },
            'POST /api/v1/whatsapp/session': { body: { data: makeSession({ status: 'pending' }) } },
        });

        const wrapper = await mountPanel();

        expect(wrapper.get('[data-testid="whatsapp-status"]').text()).toBe('Erro');
        expect(wrapper.get('[data-testid="whatsapp-error-message"]').text()).toBe('O serviço do WhatsApp não respondeu.');
        const retry = wrapper.get('[data-testid="whatsapp-retry"]');
        expect(retry.text()).toBe('Tentar novamente');

        await retry.trigger('click');
        await flushPromises();

        expect(find(calls, 'POST', '/api/v1/whatsapp/session')).toBeDefined();
        expect(wrapper.find('[data-testid="whatsapp-error"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="whatsapp-status"]').text()).toBe('Pendente');

        wrapper.unmount();
    });

    it('falls back to a generic message when the error has no detail', async () => {
        mockFetch({ 'GET /api/v1/whatsapp/session': { body: { data: makeSession({ status: 'error', last_error: null }) } } });

        const wrapper = await mountPanel();

        expect(wrapper.get('[data-testid="whatsapp-error-message"]').text()).toBe(
            'Não foi possível falar com o serviço do WhatsApp. Tente novamente.',
        );

        wrapper.unmount();
    });

    it('confirms the consequences in a modal before revoking consent (UI-04, CT-02)', async () => {
        let revoked = false;
        const { calls } = mockFetch({
            'GET /api/v1/whatsapp/session': () => ({
                body: {
                    data: makeSession({
                        status: revoked ? 'disconnected' : 'connected',
                        phone_masked: '+55 11 *****-8888',
                        consent: { version: 1, scopes: ['leitura'], valid: !revoked },
                    }),
                },
            }),
            'DELETE /api/v1/whatsapp/consent': () => {
                revoked = true;

                return { status: 204 };
            },
        });

        const wrapper = await mountPanel();

        expect(wrapper.get('[data-testid="whatsapp-phone"]').text()).toContain('+55 11 *****-8888');

        await wrapper.get('[data-testid="whatsapp-revoke"]').trigger('click');

        const modal = wrapper.get('[data-testid="whatsapp-confirm-modal"]');
        expect(modal.text()).toContain('Revogar consentimento do WhatsApp');
        expect(modal.text()).toContain('todas as mensagens armazenadas são apagadas');
        expect(find(calls, 'DELETE', '/api/v1/whatsapp/consent')).toBeUndefined();

        await wrapper.get('[data-testid="whatsapp-confirm-cancel"]').trigger('click');
        expect(wrapper.find('[data-testid="whatsapp-confirm-modal"]').exists()).toBe(false);
        expect(find(calls, 'DELETE', '/api/v1/whatsapp/consent')).toBeUndefined();

        await wrapper.get('[data-testid="whatsapp-revoke"]').trigger('click');
        await wrapper.get('[data-testid="whatsapp-confirm-submit"]').trigger('click');
        await flushPromises();

        expect(find(calls, 'DELETE', '/api/v1/whatsapp/consent')).toBeDefined();
        expect(wrapper.get('[data-testid="whatsapp-status"]').text()).toBe('Desconectado');
        expect(wrapper.find('[data-testid="whatsapp-consent"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="whatsapp-revoke"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('confirms the consequences in a modal before removing WhatsApp (UI-04, CT-07)', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/whatsapp/session': { body: { data: makeSession({ status: 'connected' }) } },
            'DELETE /api/v1/whatsapp/session': { status: 204 },
        });

        const wrapper = await mountPanel();

        await wrapper.get('[data-testid="whatsapp-remove"]').trigger('click');

        const modal = wrapper.get('[data-testid="whatsapp-confirm-modal"]');
        expect(modal.text()).toContain('Remover WhatsApp');
        expect(modal.text()).toContain('Esta ação não pode ser desfeita.');
        expect(find(calls, 'DELETE', '/api/v1/whatsapp/session')).toBeUndefined();

        await wrapper.get('[data-testid="whatsapp-confirm-submit"]').trigger('click');
        await flushPromises();

        expect(find(calls, 'DELETE', '/api/v1/whatsapp/session')).toBeDefined();
        expect(wrapper.find('[data-testid="whatsapp-status"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="whatsapp-remove"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="whatsapp-connect"]').text()).toBe('Conectar WhatsApp');

        wrapper.unmount();
    });
});
