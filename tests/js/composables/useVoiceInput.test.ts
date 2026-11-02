import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { useVoiceInput } from '@/composables/useVoiceInput';
import { useAssistantStore } from '@/stores/assistant';
import { useAuthStore } from '@/stores/auth';
import { useConversationsStore } from '@/stores/conversations';
import { DEFAULT_PREFERENCES } from '@/stores/preferences';
import { useToastStore } from '@/stores/toast';
import { makeUser, mockFetch } from '../helpers/fetch';
import { makeResultEvent, stubSpeechRecognition } from '../helpers/speech';

interface MicStub {
    runLevelFrame: () => void;
}

/** Stubs getUserMedia + AudioContext + requestAnimationFrame for the level meter. */
function stubMic(options: { deny?: boolean } = {}): MicStub {
    const tracks = [{ stop: vi.fn() }];
    const stream = { getTracks: () => tracks };
    const getUserMedia = options.deny
        ? vi.fn(async () => {
              throw { name: 'NotAllowedError' };
          })
        : vi.fn(async () => stream);

    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia } });

    const analyser = {
        fftSize: 256,
        getByteTimeDomainData: vi.fn((array: Uint8Array) => array.fill(200)),
        connect: vi.fn(),
    };
    const context = {
        createAnalyser: vi.fn(() => analyser),
        createMediaStreamSource: vi.fn(() => ({ connect: vi.fn() })),
        close: vi.fn(async () => undefined),
    };
    // A regular function so `new AudioContext()` can construct it.
    function AudioContextMock(): unknown {
        return context;
    }
    vi.stubGlobal('AudioContext', AudioContextMock);

    let frameCallback: (() => void) | null = null;
    vi.stubGlobal('requestAnimationFrame', vi.fn((callback: () => void) => {
        frameCallback = callback;

        return 1;
    }));
    vi.stubGlobal('cancelAnimationFrame', vi.fn());

    return { runLevelFrame: () => frameCallback?.() };
}

function seedPreferences(overrides: Record<string, unknown> = {}): void {
    useAuthStore().user = makeUser({ preference: { ...DEFAULT_PREFERENCES, ...overrides } });
}

function sentBodies(calls: Array<{ method: string; url: string; init: RequestInit | undefined }>) {
    return calls
        .filter((call) => call.method === 'POST' && call.url === '/api/v1/conversations/c1/messages')
        .map((call) => JSON.parse(String(call.init?.body)) as { content: string; source: string });
}

/** Seeds preferences, opens a conversation and routes the message send. */
function setupVoice() {
    const { calls } = mockFetch({
        'POST /api/v1/conversations/c1/messages': {
            status: 202,
            body: { data: { message_id: 'm1', conversation_id: 'c1' } },
        },
    });
    seedPreferences();
    useConversationsStore().activeId = 'c1';

    const voice = useVoiceInput();

    return { voice, calls };
}

describe('useVoiceInput', () => {
    it('sends the final transcript with source voice after silence', async () => {
        vi.useFakeTimers();
        const { instances } = stubSpeechRecognition();
        stubMic();
        const { voice, calls } = setupVoice();
        const assistant = useAssistantStore();

        await voice.start();
        expect(assistant.state).toBe('LISTENING');

        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'olá jarvis', isFinal: false }]));
        expect(voice.partialText.value).toBe('olá jarvis');

        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'bom dia', isFinal: true }]));
        expect(voice.partialText.value).toBe('bom dia');

        await vi.advanceTimersByTimeAsync(1500);
        await flushPromises();

        expect(sentBodies(calls)).toEqual([{ content: 'bom dia', source: 'voice' }]);
        expect(assistant.state).toBe('IDLE');
        expect(voice.partialText.value).toBe('');
    });

    it('every transcript resets the silence clock', async () => {
        vi.useFakeTimers();
        const { instances } = stubSpeechRecognition();
        stubMic();
        const { voice, calls } = setupVoice();

        await voice.start();
        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'o', isFinal: false }]));

        await vi.advanceTimersByTimeAsync(1400);
        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'olá', isFinal: false }]));

        await vi.advanceTimersByTimeAsync(1400);
        expect(sentBodies(calls)).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(200);
        await flushPromises();
        expect(sentBodies(calls)).toEqual([{ content: 'olá', source: 'voice' }]);
    });

    it('the voice button stops the capture and sends what was heard', async () => {
        vi.useFakeTimers();
        const { instances } = stubSpeechRecognition();
        stubMic();
        const { voice, calls } = setupVoice();
        const assistant = useAssistantStore();

        await voice.toggle();
        expect(assistant.state).toBe('LISTENING');
        expect(instances[0]?.interimResults).toBe(true);

        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'acenda as luzes', isFinal: false }]));

        await voice.toggle();
        await flushPromises();

        expect(sentBodies(calls)).toEqual([{ content: 'acenda as luzes', source: 'voice' }]);
        expect(assistant.state).toBe('IDLE');
    });

    it('starts the recognition with the speech_locale from the preferences', async () => {
        stubMic();
        seedPreferences({ speech_locale: 'en-US' });

        const { instances } = stubSpeechRecognition();
        const voice = useVoiceInput();
        await voice.start();

        expect(instances[0]?.lang).toBe('en-US');
    });

    it('cancels a spoken response when push-to-talk starts', async () => {
        const cancel = vi.fn();
        vi.stubGlobal('speechSynthesis', { cancel });
        const { instances } = stubSpeechRecognition();
        stubMic();
        const { voice } = setupVoice();

        await voice.start();

        expect(instances).toHaveLength(1);
        expect(cancel).toHaveBeenCalledTimes(1);
    });

    it('denied microphone permission shows a toast and disables further starts', async () => {
        const { instances } = stubSpeechRecognition();
        stubMic({ deny: true });
        const { voice } = setupVoice();
        const assistant = useAssistantStore();
        const toasts = useToastStore();

        await voice.start();
        await flushPromises();

        expect(voice.permissionDenied.value).toBe(true);
        expect(assistant.state).toBe('IDLE');
        expect(toasts.toasts.some((toast) => toast.variant === 'warning')).toBe(true);
        expect(instances[0]?.stopped).toBe(true);

        await voice.start();
        expect(instances).toHaveLength(1);
    });

    it('does not start capture when speech recognition is unsupported', async () => {
        stubMic();
        const { voice } = setupVoice();
        const assistant = useAssistantStore();

        await voice.start();

        expect(voice.supported).toBe(false);
        expect(voice.blocked.value).toBe(true);
        expect(assistant.state).toBe('IDLE');
    });

    it('Esc finalizes the capture and sends the transcript', async () => {
        vi.useFakeTimers();
        const { instances } = stubSpeechRecognition();
        stubMic();
        const { voice, calls } = setupVoice();
        const assistant = useAssistantStore();

        await voice.start();
        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'pare', isFinal: false }]));

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();

        expect(sentBodies(calls)).toEqual([{ content: 'pare', source: 'voice' }]);
        expect(assistant.state).toBe('IDLE');
    });

    it('feeds the JarvisCore level from the getUserMedia analyser', async () => {
        stubSpeechRecognition();
        const mic = stubMic();
        const { voice } = setupVoice();
        const assistant = useAssistantStore();

        await voice.start();
        await flushPromises();

        mic.runLevelFrame();

        expect(assistant.audioLevel).toBeGreaterThan(0);

        await voice.finish();
        expect(assistant.audioLevel).toBe(0);
    });

    it('does not send when nothing was heard', async () => {
        vi.useFakeTimers();
        const { instances } = stubSpeechRecognition();
        stubMic();
        const { voice, calls } = setupVoice();

        await voice.start();
        await vi.advanceTimersByTimeAsync(1500);
        await flushPromises();

        expect(instances[0]?.stopped).toBe(true);
        expect(sentBodies(calls)).toHaveLength(0);
    });
});
