import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { useSpeechOutput } from '@/composables/useSpeechOutput';
import { useAssistantStore } from '@/stores/assistant';
import { useAuthStore } from '@/stores/auth';
import { DEFAULT_PREFERENCES, usePreferencesStore } from '@/stores/preferences';
import type { MessageChannel } from '@/stores/messages';
import type { Message } from '@/types/models';
import { makeUser } from '../helpers/fetch';

interface FakeUtterance {
    text: string;
    lang: string;
    rate: number;
    voice: unknown;
    onstart: (() => void) | null;
    onend: (() => void) | null;
    onerror: (() => void) | null;
    onboundary: (() => void) | null;
}

interface FakeChannel extends MessageChannel {
    emit(event: string, payload: unknown): void;
}

function makeChannel(): FakeChannel {
    const listeners = new Map<string, (payload: unknown) => void>();

    return {
        listen(event: string, callback: (payload: unknown) => void) {
            listeners.set(event, callback);

            return undefined;
        },
        emit(event: string, payload: unknown) {
            listeners.get(`.${event}`)?.(payload);
        },
    };
}

function stubSpeechSynthesis(voices: Array<{ name: string; lang: string }> = []) {
    const utterances: FakeUtterance[] = [];

    class FakeSpeechSynthesisUtterance {
        text: string;
        lang = '';
        rate = 1;
        voice: unknown = null;
        onstart: (() => void) | null = null;
        onend: (() => void) | null = null;
        onerror: (() => void) | null = null;
        onboundary: (() => void) | null = null;

        constructor(text: string) {
            this.text = text;
            utterances.push(this as unknown as FakeUtterance);
        }
    }

    const synth = {
        speak: vi.fn(),
        cancel: vi.fn(),
        pause: vi.fn(),
        resume: vi.fn(),
        getVoices: () => voices,
    };

    vi.stubGlobal('SpeechSynthesisUtterance', FakeSpeechSynthesisUtterance);
    vi.stubGlobal('speechSynthesis', synth);

    return { synth, utterances };
}

function seedPreferences(overrides: Record<string, unknown> = {}): void {
    useAuthStore().user = makeUser({ preference: { ...DEFAULT_PREFERENCES, ...overrides } });
}

function makeMessage(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        conversation_id: 'c1',
        role: 'assistant',
        source: null,
        status: 'completed',
        content: 'texto da resposta',
        payload: null,
        ai_model_id: null,
        created_at: '2026-11-02T10:00:00.000000Z',
        ...overrides,
    };
}


async function emitCompleted(channel: FakeChannel, message: Message): Promise<void> {
    channel.emit('assistant.response.completed', { message });
    await flushPromises();
}

async function setupSpeech(options: { enabled?: boolean; sleeping?: boolean } = {}) {
    const { synth, utterances } = stubSpeechSynthesis([
        { name: 'Google português do Brasil', lang: 'pt-BR' },
        { name: 'Google US English', lang: 'en-US' },
        { name: 'Voz personalizada', lang: 'pt-BR' },
    ]);
    seedPreferences({ spoken_responses_enabled: options.enabled ?? true });
    const channel = makeChannel();
    const assistant = useAssistantStore();
    const speech = useSpeechOutput();

    speech.bind(channel);

    if (options.sleeping === true) {
        assistant.sleep();
    }

    // Wait for the preferences to be seeded from the me envelope.
    await flushPromises();

    return { synth, utterances, channel, assistant, speech };
}

describe('useSpeechOutput', () => {
    it('speaks the payload text when spoken responses are enabled', async () => {
        const { synth, utterances, channel } = await setupSpeech();

        await emitCompleted(channel, makeMessage({ content: '', payload: { type: 'text', text: 'Olá, operador.' } }));

        expect(synth.speak).toHaveBeenCalledTimes(1);
        expect(utterances[0]?.text).toBe('Olá, operador.');
        expect(utterances[0]?.lang).toBe('pt-BR');
        expect(utterances[0]?.rate).toBe(1);
    });

    it('prefers payload.speech over payload.text', async () => {
        const { utterances, channel } = await setupSpeech();

        await emitCompleted(channel, makeMessage({
                content: '',
                payload: { type: 'text', text: 'texto longo', speech: 'resumo falado' },
            }));

        expect(utterances[0]?.text).toBe('resumo falado');
    });

    it('does not speak structured payloads without a speakable summary', async () => {
        const { synth, channel } = await setupSpeech();

        await emitCompleted(channel, makeMessage({ content: 'tabela markdown crua', payload: { type: 'table', rows: [] } }));

        expect(synth.speak).not.toHaveBeenCalled();
    });

    it('falls back to the message content when the payload is null', async () => {
        const { utterances, channel } = await setupSpeech();

        await emitCompleted(channel, makeMessage({ content: 'resposta simples', payload: null }));

        expect(utterances[0]?.text).toBe('resposta simples');
    });

    it('stays silent when spoken responses are disabled', async () => {
        const { synth, channel } = await setupSpeech({ enabled: false });

        await emitCompleted(channel, makeMessage({ payload: { type: 'text', text: 'não deve falar' } }));

        expect(synth.speak).not.toHaveBeenCalled();
    });

    it('stays silent while the core is SLEEPING', async () => {
        const { synth, channel } = await setupSpeech({ sleeping: true });

        await emitCompleted(channel, makeMessage({ payload: { type: 'text', text: 'silêncio' } }));

        expect(synth.speak).not.toHaveBeenCalled();
    });

    it('goes SPEAKING on start and IDLE on end', async () => {
        const { utterances, channel, assistant } = await setupSpeech();

        await emitCompleted(channel, makeMessage({ payload: { type: 'text', text: 'falando' } }));

        utterances[0]?.onstart?.();
        expect(assistant.state).toBe('SPEAKING');

        utterances[0]?.onend?.();
        expect(assistant.state).toBe('IDLE');
    });

    it('stop cancels the synthesis and returns the core to IDLE', async () => {
        const { synth, utterances, channel, assistant, speech } = await setupSpeech();

        await emitCompleted(channel, makeMessage({ payload: { type: 'text', text: 'falando' } }));
        utterances[0]?.onstart?.();

        speech.stop();

        expect(synth.cancel).toHaveBeenCalled();
        expect(assistant.state).toBe('IDLE');
    });

    it('pause and resume drive the synthesis', async () => {
        const { synth, speech } = await setupSpeech();

        speech.pause();
        speech.resume();

        expect(synth.pause).toHaveBeenCalledTimes(1);
        expect(synth.resume).toHaveBeenCalledTimes(1);
    });

    it('selects the preferred voice by name, falling back to the speech locale', async () => {
        const { utterances, channel } = await setupSpeech();

        await emitCompleted(channel, makeMessage({ payload: { type: 'text', text: 'com voz' } }));

        expect((utterances[0]?.voice as { name?: string } | null)?.name).toBe('Google português do Brasil');

        usePreferencesStore().preference = { ...DEFAULT_PREFERENCES, voice_name: 'Voz personalizada' };

        await emitCompleted(channel, makeMessage({ payload: { type: 'text', text: 'com voz nomeada' } }));

        expect((utterances[1]?.voice as { name?: string } | null)?.name).toBe('Voz personalizada');
    });

    it('Esc cancels the spoken response', async () => {
        const { synth, utterances, channel, assistant } = await setupSpeech();

        await emitCompleted(channel, makeMessage({ payload: { type: 'text', text: 'falando' } }));
        utterances[0]?.onstart?.();
        expect(assistant.state).toBe('SPEAKING');

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(synth.cancel).toHaveBeenCalled();
        expect(assistant.state).toBe('IDLE');
    });

    it('a new request (THINKING) interrupts the speech', async () => {
        const { synth, utterances, channel, assistant } = await setupSpeech();

        await emitCompleted(channel, makeMessage({ payload: { type: 'text', text: 'falando' } }));
        utterances[0]?.onstart?.();

        assistant.setState('THINKING');

        expect(synth.cancel).toHaveBeenCalled();
        expect(assistant.state).toBe('THINKING');
    });

    it('onboundary pulses the core level', async () => {
        const { utterances, channel, assistant } = await setupSpeech();

        await emitCompleted(channel, makeMessage({ payload: { type: 'text', text: 'animando' } }));

        utterances[0]?.onboundary?.();

        expect(assistant.audioLevel).toBeGreaterThan(0);
    });
});
