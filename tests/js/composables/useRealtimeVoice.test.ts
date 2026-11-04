import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useRealtimeVoice } from '@/composables/useRealtimeVoice';
import { RealtimeTransportError } from '@/lib/voice/RealtimeTransport';
import { acquireVoiceTabLock, VOICE_LOCK_NAME } from '@/lib/voice/voiceTabLock';
import { useAuthStore } from '@/stores/auth';
import { DEFAULT_PREFERENCES, usePreferencesStore } from '@/stores/preferences';
import { useToastStore } from '@/stores/toast';
import { useVoiceStore } from '@/stores/voice';
import type { TaskSummary } from '@/types/models';
import { makeUser, mockFetch, type MockRoutes } from '../helpers/fetch';
import { FakeRealtimeTransport } from '../helpers/realtime';
import { makeResultEvent, stubSpeechRecognition } from '../helpers/speech';

const disposers: Array<() => void> = [];

afterEach(() => {
    disposers.splice(0).forEach((dispose) => dispose());
});

function grant(overrides: Record<string, unknown> = {}) {
    return {
        voice_session_id: 7,
        conversation_id: 42,
        client_secret: 'ek_test',
        expires_at: '2026-11-04T10:01:00Z',
        model: 'gpt-realtime',
        voice: 'marin',
        listening_window_seconds: 60,
        ...overrides,
    };
}

function task(overrides: Partial<TaskSummary> = {}): TaskSummary {
    return { id: '5', title: 'Busca no blog', status: 'running', progress: 0, ...overrides };
}

interface SetupOptions {
    consentValid?: boolean;
    routes?: MockRoutes;
    lockHeld?: boolean;
}

async function setup(options: SetupOptions = {}) {
    const recognition = stubSpeechRecognition();
    const speechSynthesis = { speak: vi.fn(), cancel: vi.fn() };
    vi.stubGlobal('speechSynthesis', speechSynthesis);
    const { calls } = mockFetch({
        'POST /api/v1/voice/sessions': { status: 201, body: { data: grant() } },
        'POST /api/v1/voice/sessions/7/turns': { status: 201, body: { data: [] } },
        'POST /api/v1/voice/sessions/7/usage': { status: 201, body: { data: { session_total_tokens: 1, session_token_ceiling: 200000 } } },
        ...options.routes,
    });

    useAuthStore().user = makeUser({
        preference: { ...DEFAULT_PREFERENCES, wake_word_enabled: true, wake_word_consent_valid: options.consentValid ?? true },
    });
    await usePreferencesStore().ensureLoaded();

    const transports: FakeRealtimeTransport[] = [];
    const release = vi.fn();
    const realtime = useRealtimeVoice({
        createTransport: () => {
            const transport = new FakeRealtimeTransport();
            transports.push(transport);

            return transport;
        },
        isSupported: () => true,
        tabLock: { held: ref(options.lockHeld ?? true), release },
    });
    disposers.push(realtime.dispose);
    await flushPromises();

    const sessionCalls = () => calls.filter((call) => call.url === '/api/v1/voice/sessions');

    return { ...realtime, recognition, speechSynthesis, calls, sessionCalls, transports, release, store: useVoiceStore() };
}

function sayWakeWord(recognition: ReturnType<typeof stubSpeechRecognition>, phrase = 'Jarvis!'): void {
    const instance = recognition.instances[recognition.instances.length - 1];

    instance?.onresult?.(makeResultEvent([{ transcript: phrase, isFinal: false }]));
}

describe('useRealtimeVoice', () => {
    it.each(['Jarvis!', 'Hey Jarvis', 'Ei Jarvis'])('"%s" opens the window and greets with "Pode falar."', async (phrase) => {
        const { recognition, store, sessionCalls, transports } = await setup();

        expect(sessionCalls()).toHaveLength(0);
        expect(store.phase).toBe('sleeping');

        sayWakeWord(recognition, phrase);
        await flushPromises();

        expect(sessionCalls()).toHaveLength(1);
        expect(store.phase).toBe('listening');
        expect(store.countdownSeconds).toBe(60);
        expect(transports[0]?.connectedWith).toEqual({ clientSecret: 'ek_test' });
        expect(JSON.stringify(transports[0]?.sent)).toContain('Pode falar.');
    });

    it('does not call CT-01 nor open the window without a valid consent', async () => {
        const { recognition, sessionCalls, store } = await setup({ consentValid: false });

        expect(recognition.instances).toHaveLength(0);
        expect(sessionCalls()).toHaveLength(0);
        expect(store.phase).toBe('sleeping');
    });

    it('a muted microphone stops the wake word', async () => {
        const { recognition, toggleMute, sessionCalls, store } = await setup();

        toggleMute();
        await flushPromises();

        expect(store.muted).toBe(true);
        expect(recognition.instances[0]?.stopped).toBe(true);

        sayWakeWord(recognition);
        await flushPromises();
        expect(sessionCalls()).toHaveLength(0);
    });

    it('counts down 60 → 59 and restarts on every turn', async () => {
        vi.useFakeTimers();
        const { recognition, store, transports } = await setup();
        sayWakeWord(recognition);
        await flushPromises();

        await vi.advanceTimersByTimeAsync(1000);
        expect(store.countdownSeconds).toBe(59);

        transports[0]?.emit({ type: 'input_audio_buffer.speech_started' });
        expect(store.phase).toBe('user_speaking');
        expect(store.countdownSeconds).toBe(60);

        // Paused while the operator speaks (D8).
        await vi.advanceTimersByTimeAsync(3000);
        expect(store.countdownSeconds).toBe(60);

        transports[0]?.emit({ type: 'input_audio_buffer.speech_stopped' });
        await vi.advanceTimersByTimeAsync(1000);
        expect(store.countdownSeconds).toBe(59);
    });

    it('at 0 without a voice task goes back to sleeping and closes the session', async () => {
        vi.useFakeTimers();
        const { recognition, store, transports, calls } = await setup({
            routes: { 'POST /api/v1/voice/sessions': { status: 201, body: { data: grant({ listening_window_seconds: 15 }) } } },
        });
        sayWakeWord(recognition);
        await flushPromises();
        transports[0]?.emit({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'i1', transcript: 'oi' });

        await vi.advanceTimersByTimeAsync(15000);

        expect(store.phase).toBe('sleeping');
        expect(store.countdownSeconds).toBeNull();
        expect(store.voiceSessionId).toBeNull();
        expect(transports[0]?.closed).toBe(true);
        expect(calls.some((call) => call.url === '/api/v1/voice/sessions/7/turns')).toBe(true);
        // The wake word listens again.
        expect(recognition.instances.length).toBeGreaterThan(1);
    });

    it('at 0 with a running voice task restarts the countdown and keeps the session', async () => {
        vi.useFakeTimers();
        const { recognition, store, transports } = await setup({
            routes: {
                'POST /api/v1/voice/sessions': { status: 201, body: { data: grant({ listening_window_seconds: 15 }) } },
                'POST /api/v1/voice/tasks': { status: 201, body: { data: task() } },
            },
        });
        sayWakeWord(recognition);
        await flushPromises();
        await store.startTask('procure a aula');

        await vi.advanceTimersByTimeAsync(15000);

        expect(store.countdownSeconds).toBe(15);
        expect(store.sessionOpen).toBe(true);
        expect(transports[0]?.closed).toBe(false);
    });

    it('Dormir closes the session and Acordar reopens it without the wake word', async () => {
        const { recognition, store, transports, sleep, wake, sessionCalls } = await setup();
        sayWakeWord(recognition);
        await flushPromises();

        await sleep();
        expect(store.phase).toBe('sleeping');
        expect(transports[0]?.closed).toBe(true);

        await wake();
        await flushPromises();
        expect(sessionCalls()).toHaveLength(2);
        expect(store.phase).toBe('listening');
    });

    it('mute zeroes the MIC and disables the mic track while the output keeps playing', async () => {
        vi.useFakeTimers();
        const { recognition, store, transports, toggleMute } = await setup();
        sayWakeWord(recognition);
        await flushPromises();
        const transport = transports[0] as FakeRealtimeTransport;
        transport.mic = 0.6;
        transport.output = 0.4;

        toggleMute();
        await vi.advanceTimersByTimeAsync(200);

        expect(transport.micEnabled).toBe(false);
        expect(transport.closed).toBe(false);
        expect(store.micLevel).toBe(0);
        expect(store.outputLevel).toBe(0.4);
    });

    it('CT-01 409 leaves the HUD text-only without Web Speech conversation nor speechSynthesis', async () => {
        const { recognition, store, speechSynthesis, transports } = await setup({
            routes: { 'POST /api/v1/voice/sessions': { status: 409, body: { message: 'A voz está indisponível.' } } },
        });
        sayWakeWord(recognition);
        await flushPromises();

        expect(store.available).toBe(false);
        expect(store.unavailableReason).toBe('no_realtime');
        expect(store.phase).toBe('sleeping');
        expect(recognition.instances).toHaveLength(1);
        expect(recognition.instances[0]?.stopped).toBe(true);
        expect(transports).toHaveLength(0);
        expect(speechSynthesis.speak).not.toHaveBeenCalled();
    });

    it('a denied microphone toasts in pt-BR and never leaves the core listening', async () => {
        const { recognition, store } = await setup();
        const failing = new FakeRealtimeTransport();
        failing.failWith = new RealtimeTransportError('mic_denied');

        const realtime = useRealtimeVoice({ createTransport: () => failing, isSupported: () => true, tabLock: { held: ref(true), release: vi.fn() } });
        disposers.push(realtime.dispose);
        await realtime.wake();

        expect(store.phase).toBe('sleeping');
        expect(store.unavailableReason).toBe('mic_denied');
        expect(useToastStore().toasts.map((toast) => toast.message)).toContain('Permissão de microfone negada — a voz foi desativada.');
        expect(recognition.instances.every((instance) => instance.stopped)).toBe(true);
    });

    it('announces a finished voice task in the open session', async () => {
        const { recognition, store, transports, onTaskUpdated } = await setup();
        sayWakeWord(recognition);
        await flushPromises();
        store.voiceTasks = { '5': 'running' };
        transports[0]?.emit({ type: 'response.done', response: { id: 'greeting' } });
        const sentBefore = transports[0]?.sent.length ?? 0;

        onTaskUpdated(task({ status: 'completed', voice_summary: '<tool_result trusted="false">Aula 12</tool_result>' }));

        const sent = transports[0]?.sent.slice(sentBefore) ?? [];
        expect(sent.map((event) => event.type)).toEqual(['conversation.item.create', 'response.create']);
        expect(JSON.stringify(sent[0])).toContain('Aula 12');
    });

    it('warns by voice about a pending approval', async () => {
        const { recognition, store, transports, onTaskUpdated } = await setup();
        sayWakeWord(recognition);
        await flushPromises();
        store.voiceTasks = { '5': 'running' };
        transports[0]?.emit({ type: 'response.done', response: { id: 'greeting' } });

        onTaskUpdated(task({ status: 'waiting_approval' }));

        expect(JSON.stringify(transports[0]?.sent.at(-1))).toContain('aguardando sua aprovação');
    });

    it('a task finished while sleeping only shows a toast', async () => {
        const { store, sessionCalls, speechSynthesis, transports, bind } = await setup();
        const listeners = new Map<string, (payload: unknown) => void>();
        bind({ listen: (event, callback) => listeners.set(event, callback) });
        store.voiceTasks = { '5': 'running' };

        listeners.get('.task.updated')?.({ task: task({ status: 'completed' }) });

        expect(useToastStore().toasts[0]?.message).toBe('Tarefa concluída: Busca no blog');
        expect(sessionCalls()).toHaveLength(0);
        expect(transports).toHaveLength(0);
        expect(speechSynthesis.speak).not.toHaveBeenCalled();
    });

    it('ignores tasks not started by voice', async () => {
        const { onTaskUpdated } = await setup();

        onTaskUpdated(task({ id: '99', status: 'completed' }));

        expect(useToastStore().toasts).toHaveLength(0);
    });

    it('a tab without the voice lock stays unavailable', async () => {
        const { recognition, store, sessionCalls, wake } = await setup({ lockHeld: false });

        expect(store.unavailableReason).toBe('other_tab');
        expect(recognition.instances).toHaveLength(0);

        await wake();
        expect(sessionCalls()).toHaveLength(0);
    });

    it('renews the credential of the same session when the transport drops', async () => {
        const { recognition, transports, sessionCalls, store } = await setup();
        sayWakeWord(recognition);
        await flushPromises();

        transports[0]?.drop();
        await flushPromises();

        expect(JSON.parse(String(sessionCalls()[1]?.init?.body))).toMatchObject({ voice_session_id: 7 });
        expect(transports).toHaveLength(2);
        expect(store.sessionOpen).toBe(true);
    });

    it('dispose releases the tab lock', async () => {
        const { dispose, release } = await setup();

        dispose();

        expect(release).toHaveBeenCalled();
    });
});

describe('acquireVoiceTabLock', () => {
    afterEach(() => {
        delete (navigator as unknown as Record<string, unknown>).locks;
    });

    it('is held at once without Web Locks', () => {
        expect(acquireVoiceTabLock().held.value).toBe(true);
    });

    it('requests the jarvis-voice lock and holds it once granted', async () => {
        const request = vi.fn((_name: string, _options: unknown, callback: () => Promise<void>) => callback());
        Object.defineProperty(navigator, 'locks', { configurable: true, value: { request } });

        const lock = acquireVoiceTabLock();
        await flushPromises();

        expect(request.mock.calls[0]?.[0]).toBe(VOICE_LOCK_NAME);
        expect(lock.held.value).toBe(true);

        lock.release();
        expect(lock.held.value).toBe(false);
    });

    it('stays not held while another tab owns the lock', async () => {
        Object.defineProperty(navigator, 'locks', { configurable: true, value: { request: vi.fn(() => new Promise(() => undefined)) } });

        const lock = acquireVoiceTabLock();
        await flushPromises();

        expect(lock.held.value).toBe(false);
    });
});
