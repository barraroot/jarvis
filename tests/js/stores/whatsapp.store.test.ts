import { afterEach, describe, expect, it, vi } from 'vitest';
import { useWhatsappStore, WHATSAPP_CONSENT_VERSION } from '@/stores/whatsapp';
import type { WhatsappSchedule, WhatsappSession } from '@/types/models';
import { mockFetch, type RecordedCall } from '../helpers/fetch';

const QR = 'data:image/png;base64,iVBORw0KGgo=';

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

function makeSchedule(overrides: Partial<WhatsappSchedule> = {}): WhatsappSchedule {
    return {
        id: 3,
        to: '5511999998888@s.whatsapp.net',
        kind: 'text',
        recurrence: '0 9 * * 1',
        timezone: 'America/Sao_Paulo',
        next_run_at: '2026-11-09T12:00:00+00:00',
        approval_expires_at: '2027-02-05T12:00:00+00:00',
        status: 'active',
        created_at: '2026-11-07T12:00:00+00:00',
        ...overrides,
    };
}

function find(calls: RecordedCall[], method: string, url: string): RecordedCall | undefined {
    return calls.find((call) => call.method === method && call.url === url);
}

function bodyOf(call: RecordedCall | undefined): unknown {
    return typeof call?.init?.body === 'string' ? JSON.parse(call.init.body) : call?.init?.body;
}

describe('whatsapp store', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('load reads the session (CT-03) and its consent', async () => {
        mockFetch({ 'GET /api/v1/whatsapp/session': { body: { data: makeSession({ status: 'connected' }) } } });
        const store = useWhatsappStore();

        await store.load();

        expect(store.loaded).toBe(true);
        expect(store.session?.status).toBe('connected');
        expect(store.hasValidConsent).toBe(true);
        expect(store.currentConsentVersion).toBe(1);
    });

    it('load accepts a missing session', async () => {
        mockFetch({ 'GET /api/v1/whatsapp/session': { body: { data: null } } });
        const store = useWhatsappStore();

        await store.load();

        expect(store.session).toBeNull();
        expect(store.hasValidConsent).toBe(false);
    });

    it('grantConsent posts the current version and scopes (CT-01)', async () => {
        const { calls } = mockFetch({
            'POST /api/v1/whatsapp/consent': {
                body: { data: { version: 1, scopes: ['leitura', 'midia'], consented_at: '2026-11-07T12:00:00+00:00', valid: true } },
            },
        });
        const store = useWhatsappStore();

        await store.grantConsent(['leitura', 'midia']);

        expect(bodyOf(find(calls, 'POST', '/api/v1/whatsapp/consent'))).toEqual({
            version: WHATSAPP_CONSENT_VERSION,
            scopes: ['leitura', 'midia'],
        });
        expect(store.hasValidConsent).toBe(true);
        expect(store.consent?.scopes).toEqual(['leitura', 'midia']);
    });

    it('grantConsent uses the version announced by the session and patches it', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/whatsapp/session': {
                body: { data: makeSession({ current_consent_version: 2, consent: { version: 1, scopes: ['leitura'], valid: false } }) },
            },
            'POST /api/v1/whatsapp/consent': {
                body: { data: { version: 2, scopes: ['envio'], consented_at: '2026-11-07T12:00:00+00:00', valid: true } },
            },
        });
        const store = useWhatsappStore();
        await store.load();

        await store.grantConsent(['envio']);

        expect(bodyOf(find(calls, 'POST', '/api/v1/whatsapp/consent'))).toEqual({ version: 2, scopes: ['envio'] });
        expect(store.session?.consent).toEqual({ version: 2, scopes: ['envio'], valid: true });
    });

    it('revokeConsent calls CT-02, drops the pairing data and reloads the session', async () => {
        let revoked = false;
        const { calls } = mockFetch({
            'GET /api/v1/whatsapp/session': () => ({
                body: {
                    data: revoked
                        ? makeSession({ status: 'disconnected', consent: { version: 1, scopes: ['leitura'], valid: false } })
                        : makeSession(),
                },
            }),
            'POST /api/v1/whatsapp/session/qr': { body: { data: { qr: QR } } },
            'DELETE /api/v1/whatsapp/consent': () => {
                revoked = true;

                return { body: { data: { revoked: true } } };
            },
        });
        const store = useWhatsappStore();
        await store.load();
        await store.requestQr();

        await store.revokeConsent();

        expect(find(calls, 'DELETE', '/api/v1/whatsapp/consent')).toBeDefined();
        expect(store.qr).toBeNull();
        expect(store.hasValidConsent).toBe(false);
        expect(store.session?.status).toBe('disconnected');
    });

    it('provision posts CT-04 without a body and stores the session', async () => {
        const { calls } = mockFetch({ 'POST /api/v1/whatsapp/session': { status: 201, body: { data: makeSession() } } });
        const store = useWhatsappStore();

        await store.provision();

        expect(bodyOf(find(calls, 'POST', '/api/v1/whatsapp/session'))).toBeUndefined();
        expect(store.session?.id).toBe(7);
        expect(store.canPair).toBe(true);
    });

    it('provision propagates an upstream failure (502)', async () => {
        mockFetch({
            'POST /api/v1/whatsapp/session': { status: 502, body: { message: 'Não foi possível falar com o serviço do WhatsApp agora.' } },
        });
        const store = useWhatsappStore();

        await expect(store.provision()).rejects.toMatchObject({ status: 502 });
        expect(store.session).toBeNull();
    });

    it('requestQr (CT-05) and pairPhone (CT-06) keep only one pairing secret in memory', async () => {
        const { calls } = mockFetch({
            'POST /api/v1/whatsapp/session/qr': { body: { data: { qr: QR } } },
            'POST /api/v1/whatsapp/session/pair-phone': { body: { data: { code: 'ABCD-1234' } } },
        });
        const store = useWhatsappStore();

        await store.requestQr();
        expect(store.qr).toBe(QR);

        await store.pairPhone('5511999998888');

        expect(bodyOf(find(calls, 'POST', '/api/v1/whatsapp/session/pair-phone'))).toEqual({ phone: '5511999998888' });
        expect(store.pairingCode).toBe('ABCD-1234');
        expect(store.qr).toBeNull();
    });

    it('never writes the QR or the pairing code to browser storage', async () => {
        const setItem = vi.spyOn(Storage.prototype, 'setItem');
        mockFetch({
            'GET /api/v1/whatsapp/session': { body: { data: makeSession() } },
            'POST /api/v1/whatsapp/session/qr': { body: { data: { qr: QR } } },
            'POST /api/v1/whatsapp/session/pair-phone': { body: { data: { code: 'ABCD-1234' } } },
        });
        const store = useWhatsappStore();

        await store.load();
        await store.requestQr();
        await store.pairPhone('5511999998888');

        expect(setItem).not.toHaveBeenCalled();
        expect(localStorage.length).toBe(0);
        expect(sessionStorage.length).toBe(0);
    });

    it('remove calls CT-07 and forgets the session and schedules', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/whatsapp/session': { body: { data: makeSession() } },
            'GET /api/v1/whatsapp/schedules': { body: { data: [makeSchedule()] } },
            'DELETE /api/v1/whatsapp/session': { body: { data: { deleted: true } } },
        });
        const store = useWhatsappStore();
        await store.load();
        await store.loadSchedules();

        await store.remove();

        expect(find(calls, 'DELETE', '/api/v1/whatsapp/session')).toBeDefined();
        expect(store.session).toBeNull();
        expect(store.schedules).toEqual([]);
        expect(store.hasValidConsent).toBe(true);
    });

    it('loads, cancels and resumes schedules (CT-08)', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/whatsapp/schedules': {
                body: { data: [makeSchedule({ id: 3 }), makeSchedule({ id: 4, status: 'paused' })] },
            },
            'POST /api/v1/whatsapp/schedules/3/cancel': { body: { data: makeSchedule({ id: 3, status: 'cancelled' }) } },
            'POST /api/v1/whatsapp/schedules/4/resume': { body: { data: makeSchedule({ id: 4, status: 'active' }) } },
        });
        const store = useWhatsappStore();

        await store.loadSchedules();
        expect(store.schedulesLoaded).toBe(true);
        expect(store.schedules).toHaveLength(2);

        await store.cancelSchedule(3);
        await store.resumeSchedule(4);

        expect(bodyOf(find(calls, 'POST', '/api/v1/whatsapp/schedules/3/cancel'))).toBeUndefined();
        expect(find(calls, 'POST', '/api/v1/whatsapp/schedules/4/resume')).toBeDefined();
        expect(store.schedules.map((schedule) => schedule.status)).toEqual(['cancelled', 'active']);
    });

    it('applyStatusChange to connected updates the status and hides the QR without HTTP', async () => {
        const { fetchMock } = mockFetch({
            'GET /api/v1/whatsapp/session': { body: { data: makeSession() } },
            'POST /api/v1/whatsapp/session/qr': { body: { data: { qr: QR } } },
        });
        const store = useWhatsappStore();
        await store.load();
        await store.requestQr();
        fetchMock.mockClear();

        store.applyStatusChange({
            session_id: 7,
            status: 'connected',
            previous_status: 'pending',
            suspended: false,
            error: null,
            at: '2026-11-07T12:00:00+00:00',
        });

        expect(store.session?.status).toBe('connected');
        expect(store.session?.connected_at).toBe('2026-11-07T12:00:00+00:00');
        expect(store.qr).toBeNull();
        expect(store.pairingCode).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('applyStatusChange carries the error message and the suspension', async () => {
        mockFetch({ 'GET /api/v1/whatsapp/session': { body: { data: makeSession() } } });
        const store = useWhatsappStore();
        await store.load();

        store.applyStatusChange({
            session_id: 7,
            status: 'error',
            previous_status: 'pending',
            suspended: true,
            error: 'Não foi possível falar com o serviço do WhatsApp. Tente novamente.',
            at: '2026-11-07T12:01:00+00:00',
        });

        expect(store.session?.status).toBe('error');
        expect(store.session?.suspended).toBe(true);
        expect(store.session?.last_error).toContain('serviço do WhatsApp');
        expect(store.canPair).toBe(false);
    });

    it('applyStatusChange for an unknown session reloads it', async () => {
        const { calls } = mockFetch({ 'GET /api/v1/whatsapp/session': { body: { data: makeSession({ id: 9, status: 'connected' }) } } });
        const store = useWhatsappStore();

        store.applyStatusChange({
            session_id: 9,
            status: 'connected',
            previous_status: 'pending',
            suspended: false,
            error: null,
            at: '2026-11-07T12:00:00+00:00',
        });
        await vi.waitFor(() => expect(store.session?.id).toBe(9));

        expect(find(calls, 'GET', '/api/v1/whatsapp/session')).toBeDefined();
    });
});
