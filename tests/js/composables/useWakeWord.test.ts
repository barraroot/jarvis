import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useWakeWord, WAKE_WORD_RESTART_MAX_MS } from '@/composables/useWakeWord';
import { useAssistantStore } from '@/stores/assistant';
import { useAuthStore } from '@/stores/auth';
import { DEFAULT_PREFERENCES, usePreferencesStore } from '@/stores/preferences';
import { makeUser } from '../helpers/fetch';
import { makeResultEvent, stubSpeechRecognition } from '../helpers/speech';

const disposers: Array<() => void> = [];

afterEach(() => {
    disposers.splice(0).forEach((dispose) => dispose());
});

async function setupWakeWord(options: { enabled?: boolean } = {}) {
    useAuthStore().user = makeUser({
        preference: { ...DEFAULT_PREFERENCES, wake_word_enabled: options.enabled ?? true },
    });
    const preferences = usePreferencesStore();
    await preferences.ensureLoaded();

    const assistant = useAssistantStore();
    const onWake = vi.fn();
    const wakeWord = useWakeWord({ onWake });
    disposers.push(wakeWord.dispose);
    await flushPromises();

    return { preferences, assistant, onWake, wakeWord };
}

describe('useWakeWord', () => {
    it('runs a continuous recognition session when enabled and supported', async () => {
        const { instances } = stubSpeechRecognition();
        const { wakeWord } = await setupWakeWord();

        expect(wakeWord.supported).toBe(true);
        expect(wakeWord.listening.value).toBe(true);
        expect(instances).toHaveLength(1);
        expect(instances[0]?.continuous).toBe(true);
        expect(instances[0]?.interimResults).toBe(true);
    });

    it('detects the wake word case-insensitively across its variations', async () => {
        const { instances } = stubSpeechRecognition();
        const { onWake } = await setupWakeWord();

        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'Hey Jarvis, acenda as luzes', isFinal: false }]));
        expect(onWake).toHaveBeenCalledTimes(1);

        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'EI JARVIS', isFinal: true }]));
        expect(onWake).toHaveBeenCalledTimes(2);

        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'e aí jarvis', isFinal: false }]));
        expect(onWake).toHaveBeenCalledTimes(3);
    });

    it('ignores phrases that merely resemble the wake word', async () => {
        const { instances } = stubSpeechRecognition();
        const { onWake } = await setupWakeWord();

        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'hey arvis', isFinal: false }]));
        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'oi jarvis', isFinal: false }]));
        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'hey jarvisinho', isFinal: true }]));

        expect(onWake).not.toHaveBeenCalled();
    });

    it('restarts after the browser ends the session with exponential backoff', async () => {
        vi.useFakeTimers();
        const { instances } = stubSpeechRecognition();
        await setupWakeWord();

        const first = instances[0];
        first?.onend?.();

        await vi.advanceTimersByTimeAsync(1000);
        expect(instances).toHaveLength(2);

        instances[1]?.onend?.();

        await vi.advanceTimersByTimeAsync(1000);
        expect(instances).toHaveLength(2);

        await vi.advanceTimersByTimeAsync(1000);
        expect(instances).toHaveLength(3);
    });

    it('caps the restart backoff at 10 seconds', async () => {
        vi.useFakeTimers();
        const { instances } = stubSpeechRecognition();
        await setupWakeWord();

        for (let index = 0; index < 8; index++) {
            instances[index]?.onend?.();

            // Any advance beyond the cap fires the single pending restart.
            await vi.advanceTimersByTimeAsync(WAKE_WORD_RESTART_MAX_MS * 2);
        }

        expect(instances).toHaveLength(9);
    });

    it('stays silent during SPEAKING and resumes afterwards', async () => {
        const { instances } = stubSpeechRecognition();
        const { assistant } = await setupWakeWord();

        assistant.setState('SPEAKING');
        await flushPromises();

        expect(instances[0]?.stopped).toBe(true);

        assistant.setState('IDLE');
        await flushPromises();

        expect(instances).toHaveLength(2);
    });

    it('pauses during push-to-talk (LISTENING) so it cannot trigger itself', async () => {
        const { instances } = stubSpeechRecognition();
        const { assistant, onWake } = await setupWakeWord();

        assistant.setState('LISTENING');
        await flushPromises();

        expect(instances[0]?.stopped).toBe(true);

        // Even a forged event cannot fire while paused.
        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'hey jarvis', isFinal: false }]));
        expect(onWake).not.toHaveBeenCalled();

        assistant.setState('IDLE');
        await flushPromises();
        expect(instances).toHaveLength(2);
    });

    it('stays off when the wake word is disabled', async () => {
        const { instances } = stubSpeechRecognition();
        const { wakeWord } = await setupWakeWord({ enabled: false });

        expect(instances).toHaveLength(0);
        expect(wakeWord.listening.value).toBe(false);
    });

    it('stays off in browsers without speech recognition', async () => {
        const { wakeWord } = await setupWakeWord();

        expect(wakeWord.supported).toBe(false);
        expect(wakeWord.listening.value).toBe(false);
    });

    it('wakes the core from SLEEPING, cancels speech and fires onWake', async () => {
        const cancel = vi.fn();
        vi.stubGlobal('speechSynthesis', { cancel });
        const { instances } = stubSpeechRecognition();
        const { assistant, onWake } = await setupWakeWord();

        assistant.sleep();
        await flushPromises();
        expect(assistant.state).toBe('SLEEPING');

        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'hey jarvis', isFinal: false }]));

        expect(cancel).toHaveBeenCalled();
        expect(assistant.state).toBe('IDLE');
        expect(onWake).toHaveBeenCalledTimes(1);
        expect(instances[0]?.stopped).toBe(true);
    });

    it('pauses while the tab is hidden and resumes when visible again', async () => {
        const { instances } = stubSpeechRecognition();
        await setupWakeWord();
        expect(instances).toHaveLength(1);

        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
        document.dispatchEvent(new Event('visibilitychange'));
        await flushPromises();
        expect(instances[0]?.stopped).toBe(true);

        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
        document.dispatchEvent(new Event('visibilitychange'));
        await flushPromises();

        expect(instances).toHaveLength(2);
        expect(instances[1]?.stopped).toBe(false);

        delete (document as unknown as Record<string, unknown>).visibilityState;
    });
});
