import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory } from 'vue-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '@/App.vue';
import { useSpeechOutput } from '@/composables/useSpeechOutput';
import { useVoiceInput } from '@/composables/useVoiceInput';
import { createAppRouter } from '@/router';
import { useAssistantStore } from '@/stores/assistant';
import { useAuthStore } from '@/stores/auth';
import { useConversationsStore } from '@/stores/conversations';
import { DEFAULT_PREFERENCES } from '@/stores/preferences';
import type { Message } from '@/types/models';
import { makeUser, mockFetch } from '../helpers/fetch';
import { makeResultEvent, stubSpeechRecognition } from '../helpers/speech';

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

function stubSpeechSynthesis() {
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
        getVoices: () => [],
    };

    vi.stubGlobal('SpeechSynthesisUtterance', FakeSpeechSynthesisUtterance);
    vi.stubGlobal('speechSynthesis', synth);

    return { synth, utterances };
}

function stubMic(): void {
    const tracks = [{ stop: vi.fn() }];
    const stream = { getTracks: () => tracks };
    Object.defineProperty(navigator, 'mediaDevices', {
        configurable: true,
        value: { getUserMedia: vi.fn(async () => stream) },
    });

    const analyser = {
        fftSize: 256,
        getByteTimeDomainData: vi.fn((array: Uint8Array) => array.fill(128)),
        connect: vi.fn(),
    };
    function AudioContextMock(): unknown {
        return {
            createAnalyser: vi.fn(() => analyser),
            createMediaStreamSource: vi.fn(() => ({ connect: vi.fn() })),
            close: vi.fn(async () => undefined),
        };
    }
    vi.stubGlobal('AudioContext', AudioContextMock);
    vi.stubGlobal('requestAnimationFrame', vi.fn(() => 1));
    vi.stubGlobal('cancelAnimationFrame', vi.fn());
}

function makeMessage(content: string): Message {
    return {
        id: 'a1',
        conversation_id: 'c1',
        role: 'assistant',
        source: null,
        status: 'completed',
        content,
        payload: { type: 'text', text: content },
        ai_model_id: null,
        created_at: '2026-11-02T10:00:00.000000Z',
    };
}

function seedPreferences(): void {
    useAuthStore().user = makeUser({ preference: { ...DEFAULT_PREFERENCES } });
}

const disposers: Array<() => void> = [];

afterEach(() => {
    disposers.splice(0).forEach((dispose) => dispose());
});

async function setupComposables() {
    const { synth, utterances } = stubSpeechSynthesis();
    const { instances } = stubSpeechRecognition();
    stubMic();
    const { calls } = mockFetch({
        'POST /api/v1/conversations/c1/messages': {
            status: 202,
            body: { data: { message_id: 'm1', conversation_id: 'c1' } },
        },
    });
    seedPreferences();
    useConversationsStore().activeId = 'c1';

    const assistant = useAssistantStore();
    const voice = useVoiceInput();
    const speech = useSpeechOutput();
    disposers.push(voice.dispose, speech.dispose);

    return { synth, utterances, instances, calls, assistant, voice, speech };
}

describe('speech interruption (US-4.3)', () => {
    it('push-to-talk cancels the spoken response and frees LISTENING', async () => {
        const { synth, utterances, assistant, voice, speech } = await setupComposables();

        await speech.speak(makeMessage('resposta longa'));
        utterances[0]?.onstart?.();
        expect(assistant.state).toBe('SPEAKING');

        await voice.start();

        expect(synth.cancel).toHaveBeenCalled();
        expect(assistant.state).toBe('LISTENING');
    });

    it('Esc stops the speech and finalizes the capture so the send proceeds', async () => {
        vi.useFakeTimers();
        const { synth, utterances, instances, calls, assistant, voice, speech } = await setupComposables();

        await speech.speak(makeMessage('resposta longa'));
        utterances[0]?.onstart?.();

        await voice.start();
        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'nova solicitação', isFinal: false }]));

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();

        expect(synth.cancel).toHaveBeenCalled();
        expect(assistant.state).toBe('IDLE');

        const posts = calls.filter((call) => call.method === 'POST' && call.url === '/api/v1/conversations/c1/messages');
        expect(posts.map((call) => JSON.parse(String(call.init?.body)))).toEqual([
            { content: 'nova solicitação', source: 'voice' },
        ]);
    });

    it('the stop button cancels the speech and a new request proceeds normally', async () => {
        const { synth, utterances, assistant, voice, speech } = await setupComposables();

        await speech.speak(makeMessage('resposta longa'));
        utterances[0]?.onstart?.();
        expect(assistant.state).toBe('SPEAKING');

        speech.stop();

        expect(synth.cancel).toHaveBeenCalled();
        expect(assistant.state).toBe('IDLE');

        await voice.start();
        expect(assistant.state).toBe('LISTENING');
    });

    it('sending text from the footer interrupts the spoken response', async () => {
        const { synth } = stubSpeechSynthesis();
        const { calls } = mockFetch({
            'GET /api/v1/auth/me': { body: { data: makeUser({ preference: { ...DEFAULT_PREFERENCES } }) } },
            'POST /api/v1/conversations': {
                status: 201,
                body: { data: { id: 'c9', title: 'Nova conversa', last_activity_at: '2026-11-02T10:00:00.000000Z' } },
            },
            'GET /api/v1/conversations/c9': {
                body: { data: { id: 'c9', title: 'Nova conversa', last_activity_at: '2026-11-02T10:00:00.000000Z' }, messages: [], next_cursor: null },
            },
            'POST /api/v1/conversations/c9/messages': {
                status: 202,
                body: { data: { message_id: 'm9', conversation_id: 'c9' } },
            },
        });

        const router = createAppRouter(createMemoryHistory());
        await router.push('/');
        await router.isReady();

        const wrapper = mount(App, { global: { plugins: [router] }, attachTo: document.body });
        await flushPromises();

        useAssistantStore().setState('SPEAKING');

        await wrapper.get('[data-testid="composer"] textarea').setValue('interrompa e responda');
        await wrapper.get('[data-testid="footer-send"]').trigger('click');

        // The send creates the conversation, navigates (lazy chunk) and posts the message.
        await vi.waitFor(() => {
            expect(calls.some((call) => call.method === 'POST' && call.url === '/api/v1/conversations/c9/messages')).toBe(true);
        });

        expect(synth.cancel).toHaveBeenCalled();

        const sends = calls.filter(
            (call) => call.method === 'POST' && call.url === '/api/v1/conversations/c9/messages',
        );
        expect(sends.map((call) => JSON.parse(String(call.init?.body)))).toEqual([
            { content: 'interrompa e responda', source: 'text' },
        ]);

        wrapper.unmount();
    });
});
