import { describe, expect, it } from 'vitest';
import { useTasksStore } from '@/stores/tasks';
import { useToastStore } from '@/stores/toast';
import { VOICE_TURN_BATCH_LIMIT, useVoiceStore } from '@/stores/voice';
import type { VoiceTurn } from '@/types/models';
import { mockFetch } from '../helpers/fetch';

function grant(overrides: Record<string, unknown> = {}) {
    return {
        voice_session_id: 7,
        conversation_id: 42,
        client_secret: 'ek_test',
        expires_at: '2026-11-04T10:01:00Z',
        model: 'gpt-realtime',
        voice: 'marin',
        listening_window_seconds: 30,
        ...overrides,
    };
}

function bodyOf(init: RequestInit | undefined): Record<string, unknown> {
    return JSON.parse(String(init?.body ?? '{}')) as Record<string, unknown>;
}

function turns(count: number): VoiceTurn[] {
    return Array.from({ length: count }, (_, index) => ({
        role: index % 2 === 0 ? 'user' : 'assistant',
        content: `turno ${index + 1}`,
        spoken_at: '2026-11-04T10:00:00Z',
    }));
}

describe('voice store', () => {
    it('CT-01 201 populates the session ids and the listening window', async () => {
        const { calls } = mockFetch({ 'POST /api/v1/voice/sessions': { status: 201, body: { data: grant() } } });
        const voice = useVoiceStore();

        const result = await voice.openSession();

        expect(result?.client_secret).toBe('ek_test');
        expect(voice.voiceSessionId).toBe(7);
        expect(voice.conversationId).toBe(42);
        expect(voice.listeningWindowSeconds).toBe(30);
        expect(voice.available).toBe(true);
        expect(bodyOf(calls.find((call) => call.url === '/api/v1/voice/sessions')?.init)).not.toHaveProperty('voice_session_id');
    });

    it('CT-01 409 marks voice unavailable with reason no_realtime and toasts in pt-BR', async () => {
        mockFetch({ 'POST /api/v1/voice/sessions': { status: 409, body: { message: 'A voz está indisponível.' } } });
        const voice = useVoiceStore();

        expect(await voice.openSession()).toBeNull();

        expect(voice.available).toBe(false);
        expect(voice.unavailableReason).toBe('no_realtime');
        expect(voice.voiceSessionId).toBeNull();
        expect(useToastStore().toasts[0]?.message).toBe('A voz está indisponível.');
    });

    it('CT-01 402 toasts the cost limit without marking voice unavailable', async () => {
        mockFetch({ 'POST /api/v1/voice/sessions': { status: 402, body: {} } });
        const voice = useVoiceStore();

        expect(await voice.openSession()).toBeNull();

        expect(voice.available).toBe(true);
        expect(useToastStore().toasts[0]?.message).toBe('Limite mensal de custo atingido: a voz está pausada.');
    });

    it('CT-01 429 and 503 toast their own pt-BR messages', async () => {
        mockFetch({ 'POST /api/v1/voice/sessions': { status: 429, body: {}, headers: { 'Retry-After': '12' } } });
        const voice = useVoiceStore();

        await voice.openSession();
        expect(useToastStore().toasts.map((toast) => toast.message)).toEqual(['Muitas sessões de voz em pouco tempo. Aguarde 12 s.']);

        mockFetch({ 'POST /api/v1/voice/sessions': { status: 503, body: {} } });
        await voice.openSession();
        expect(useToastStore().toasts[1]?.message).toBe('O provedor de voz não respondeu. Tente novamente em instantes.');
    });

    it('renewSession sends the open voice_session_id', async () => {
        const { calls } = mockFetch({ 'POST /api/v1/voice/sessions': { status: 201, body: { data: grant() } } });
        const voice = useVoiceStore();

        await voice.openSession();
        await voice.renewSession();

        const sessionCalls = calls.filter((call) => call.url === '/api/v1/voice/sessions');
        expect(bodyOf(sessionCalls[1]?.init).voice_session_id).toBe(7);
    });

    it('reportUsage posts the response.done usage to CT-07', async () => {
        const { calls } = mockFetch({
            'POST /api/v1/voice/sessions': { status: 201, body: { data: grant() } },
            'POST /api/v1/voice/sessions/7/usage': { status: 201, body: { data: { session_total_tokens: 150, session_token_ceiling: 200000 } } },
        });
        const voice = useVoiceStore();
        await voice.openSession();

        const totals = await voice.reportUsage('resp_1', { input_tokens: 100, output_tokens: 50 });

        expect(totals).toEqual({ session_total_tokens: 150, session_token_ceiling: 200000 });
        expect(bodyOf(calls.find((call) => call.url.endsWith('/usage'))?.init)).toEqual({
            response_id: 'resp_1',
            usage: { input_tokens: 100, output_tokens: 50 },
        });
    });

    it('reportUsage toasts when the session ceiling is exceeded', async () => {
        mockFetch({
            'POST /api/v1/voice/sessions': { status: 201, body: { data: grant() } },
            'POST /api/v1/voice/sessions/7/usage': { status: 422, body: { message: 'Limite atingido.', errors: {} } },
        });
        const voice = useVoiceStore();
        await voice.openSession();

        expect(await voice.reportUsage('resp_1', { input_tokens: 1, output_tokens: 1 })).toBeNull();
        expect(useToastStore().toasts[0]?.message).toBe('Limite atingido.');
    });

    it('flushTurns sends at most 20 turns per request, in order', async () => {
        const { calls } = mockFetch({
            'POST /api/v1/voice/sessions': { status: 201, body: { data: grant() } },
            'POST /api/v1/voice/sessions/7/turns': { status: 201, body: { data: [] } },
        });
        const voice = useVoiceStore();
        await voice.openSession();

        expect(await voice.flushTurns(turns(45))).toBe(true);

        const batches = calls.filter((call) => call.url.endsWith('/turns')).map((call) => bodyOf(call.init).turns as VoiceTurn[]);
        expect(batches.map((batch) => batch.length)).toEqual([VOICE_TURN_BATCH_LIMIT, VOICE_TURN_BATCH_LIMIT, 5]);
        expect(batches[0]?.[0]?.content).toBe('turno 1');
        expect(batches[2]?.[4]?.content).toBe('turno 45');
    });

    it('startTask always sends the voice_session_id of the open session and tracks the task', async () => {
        const { calls } = mockFetch({
            'POST /api/v1/voice/sessions': { status: 201, body: { data: grant() } },
            'POST /api/v1/voice/tasks': { status: 201, body: { data: { id: 5, title: 'Busca', status: 'queued', progress: 0 } } },
        });
        const voice = useVoiceStore();
        await voice.openSession();

        const task = await voice.startTask('procure a aula de inglês', 3);

        expect(task?.id).toBe(5);
        expect(bodyOf(calls.find((call) => call.url === '/api/v1/voice/tasks')?.init)).toEqual({
            voice_session_id: 7,
            request: 'procure a aula de inglês',
            visualization_id: 3,
        });
        expect(voice.isVoiceTask(5)).toBe(true);
        expect(voice.hasActiveVoiceTask).toBe(true);
        expect(useTasksStore().tasks).toHaveLength(1);

        voice.trackVoiceTask({ id: '5', status: 'completed' });
        expect(voice.hasActiveVoiceTask).toBe(false);
    });

    it('startTask toasts and resolves null on 404/422', async () => {
        mockFetch({
            'POST /api/v1/voice/sessions': { status: 201, body: { data: grant() } },
            'POST /api/v1/voice/tasks': { status: 422, body: { message: 'A sessão de voz expirou.', errors: {} } },
        });
        const voice = useVoiceStore();
        await voice.openSession();

        expect(await voice.startTask('pedido')).toBeNull();
        expect(useToastStore().toasts[0]?.message).toBe('A sessão de voz expirou.');
        expect(voice.hasActiveVoiceTask).toBe(false);
    });

    it('startTask without an open session never calls CT-02', async () => {
        const { calls } = mockFetch({});
        const voice = useVoiceStore();

        expect(await voice.startTask('pedido')).toBeNull();
        expect(calls).toHaveLength(0);
    });

    it('mute forces the MIC level to zero', () => {
        const voice = useVoiceStore();

        voice.setLevels({ mic: 0.8, output: 0.4 });
        voice.setMuted(true);
        voice.setLevels({ mic: 0.9 });

        expect(voice.micLevel).toBe(0);
        expect(voice.outputLevel).toBe(0.4);
    });
});
