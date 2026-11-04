import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useWakeWord, WAKE_WORD_PATTERN, WAKE_WORD_RESTART_MAX_MS } from '@/composables/useWakeWord';
import { useAuthStore } from '@/stores/auth';
import { DEFAULT_PREFERENCES, usePreferencesStore } from '@/stores/preferences';
import { makeUser } from '../helpers/fetch';
import { makeResultEvent, stubSpeechRecognition } from '../helpers/speech';

const disposers: Array<() => void> = [];

afterEach(() => {
    disposers.splice(0).forEach((dispose) => dispose());
});

async function setupWakeWord(options: { enabled?: boolean; consentValid?: boolean; active?: () => boolean } = {}) {
    useAuthStore().user = makeUser({
        preference: {
            ...DEFAULT_PREFERENCES,
            wake_word_enabled: options.enabled ?? true,
            wake_word_consent_valid: options.consentValid ?? true,
        },
    });
    const preferences = usePreferencesStore();
    await preferences.ensureLoaded();

    const onWake = vi.fn();
    const wakeWord = useWakeWord({ onWake, active: options.active });
    disposers.push(wakeWord.dispose);
    await flushPromises();

    return { preferences, onWake, wakeWord };
}

describe('useWakeWord', () => {
    it('uses the RF-01 pattern', () => {
        expect(WAKE_WORD_PATTERN.source).toBe('\\b((hey|ei)\\s+)?jarvis\\b');
    });

    it('runs a continuous recognition session when enabled, consented and supported', async () => {
        const { instances } = stubSpeechRecognition();
        const { wakeWord } = await setupWakeWord();

        expect(wakeWord.supported).toBe(true);
        expect(wakeWord.listening.value).toBe(true);
        expect(instances).toHaveLength(1);
        expect(instances[0]?.continuous).toBe(true);
        expect(instances[0]?.interimResults).toBe(true);
    });

    it.each(['Jarvis!', 'Hey Jarvis', 'Ei Jarvis', 'ei, JARVIS, acenda as luzes'])('detects "%s"', async (phrase) => {
        const { instances } = stubSpeechRecognition();
        const { onWake } = await setupWakeWord();

        instances[0]?.onresult?.(makeResultEvent([{ transcript: phrase, isFinal: false }]));

        expect(onWake).toHaveBeenCalledTimes(1);
        expect(instances[0]?.stopped).toBe(true);
    });

    it('ignores phrases that merely resemble the wake word', async () => {
        const { instances } = stubSpeechRecognition();
        const { onWake } = await setupWakeWord();

        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'hey arvis', isFinal: false }]));
        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'jarvisinho', isFinal: true }]));

        expect(onWake).not.toHaveBeenCalled();
    });

    it('never speaks on detection', async () => {
        const speechSynthesis = { cancel: vi.fn(), speak: vi.fn() };
        vi.stubGlobal('speechSynthesis', speechSynthesis);
        const { instances } = stubSpeechRecognition();
        await setupWakeWord();

        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'Jarvis', isFinal: true }]));

        expect(speechSynthesis.cancel).not.toHaveBeenCalled();
        expect(speechSynthesis.speak).not.toHaveBeenCalled();
    });

    it('stays off without a consent valid for the current text (RF-22)', async () => {
        const { instances } = stubSpeechRecognition();
        const { wakeWord } = await setupWakeWord({ consentValid: false });

        expect(instances).toHaveLength(0);
        expect(wakeWord.listening.value).toBe(false);
    });

    it('follows the host gate (muted, window open, other tab)', async () => {
        const { instances } = stubSpeechRecognition();
        const active = ref(true);
        const { onWake } = await setupWakeWord({ active: () => active.value });
        expect(instances).toHaveLength(1);

        active.value = false;
        await flushPromises();
        expect(instances[0]?.stopped).toBe(true);

        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'Jarvis', isFinal: false }]));
        expect(onWake).not.toHaveBeenCalled();

        active.value = true;
        await flushPromises();
        expect(instances).toHaveLength(2);
    });

    it('restarts after the browser ends the session with exponential backoff', async () => {
        vi.useFakeTimers();
        const { instances } = stubSpeechRecognition();
        await setupWakeWord();

        instances[0]?.onend?.();

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

            await vi.advanceTimersByTimeAsync(WAKE_WORD_RESTART_MAX_MS * 2);
        }

        expect(instances).toHaveLength(9);
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
