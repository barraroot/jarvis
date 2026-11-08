import { describe, expect, it } from 'vitest';
import { bindWhatsappEvents, type MessageChannel } from '@/composables/useWhatsappEvents';
import { useWhatsappStore } from '@/stores/whatsapp';
import type { WhatsappSession } from '@/types/models';
import { mockFetch } from '../helpers/fetch';

function fakeChannel(): MessageChannel & { listened: string[]; emit(event: string, payload: unknown): void } {
    const listeners = new Map<string, (payload: unknown) => void>();

    return {
        listened: [],
        listen(event, callback) {
            this.listened.push(event);
            listeners.set(event, callback);
        },
        emit(event, payload) {
            listeners.get(`.${event}`)?.(payload);
        },
    };
}

function makeSession(overrides: Partial<WhatsappSession> = {}): WhatsappSession {
    return {
        id: 7,
        status: 'pending',
        suspended: false,
        phone_masked: null,
        last_error: null,
        last_checked_at: null,
        connected_at: null,
        consent: { version: 1, scopes: ['leitura'], valid: true },
        current_consent_version: 1,
        usage_today: { sends: 0, cap: 100 },
        ...overrides,
    };
}

describe('bindWhatsappEvents', () => {
    it('listens to .whatsapp.session.status.changed (CT-10)', () => {
        const channel = fakeChannel();

        bindWhatsappEvents(channel);

        expect(channel.listened).toEqual(['.whatsapp.session.status.changed']);
    });

    it('connected updates the status and clears the QR without HTTP', async () => {
        const { fetchMock } = mockFetch({
            'GET /api/v1/whatsapp/session': { body: { data: makeSession() } },
            'POST /api/v1/whatsapp/session/qr': { body: { data: { qr: 'data:image/png;base64,AAAA' } } },
        });
        const channel = fakeChannel();
        const store = useWhatsappStore();
        await store.load();
        await store.requestQr();
        fetchMock.mockClear();

        bindWhatsappEvents(channel);
        channel.emit('whatsapp.session.status.changed', {
            session_id: 7,
            status: 'connected',
            previous_status: 'pending',
            suspended: false,
            error: null,
            at: '2026-11-07T12:00:00+00:00',
        });

        expect(store.session?.status).toBe('connected');
        expect(store.qr).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('error carries the pt-BR message', async () => {
        mockFetch({ 'GET /api/v1/whatsapp/session': { body: { data: makeSession() } } });
        const channel = fakeChannel();
        const store = useWhatsappStore();
        await store.load();

        bindWhatsappEvents(channel);
        channel.emit('whatsapp.session.status.changed', {
            session_id: 7,
            status: 'error',
            previous_status: 'pending',
            suspended: false,
            error: 'Não foi possível falar com o serviço do WhatsApp. Tente novamente.',
            at: '2026-11-07T12:01:00+00:00',
        });

        expect(store.session?.status).toBe('error');
        expect(store.session?.last_error).toBe('Não foi possível falar com o serviço do WhatsApp. Tente novamente.');
    });

    it('ignores malformed payloads', async () => {
        const { fetchMock } = mockFetch({ 'GET /api/v1/whatsapp/session': { body: { data: makeSession() } } });
        const channel = fakeChannel();
        const store = useWhatsappStore();
        await store.load();
        fetchMock.mockClear();

        bindWhatsappEvents(channel);
        channel.emit('whatsapp.session.status.changed', null);
        channel.emit('whatsapp.session.status.changed', {});
        channel.emit('whatsapp.session.status.changed', { session_id: '7', status: 'connected' });

        expect(store.session?.status).toBe('pending');
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
