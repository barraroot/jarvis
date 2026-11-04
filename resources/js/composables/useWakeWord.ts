import { getCurrentInstance, onBeforeUnmount, ref, watch } from 'vue';
import { isSpeechRecognitionSupported, WebSpeechRecognizer } from '@/lib/voice/SpeechRecognizer';
import { usePreferencesStore } from '@/stores/preferences';

/** "Jarvis", "Hey Jarvis" or "Ei Jarvis", case-insensitive, punctuation allowed around it (RF-01). */
export const WAKE_WORD_PATTERN = /\b((hey|ei)\s+)?jarvis\b/i;

export const WAKE_WORD_RESTART_BASE_MS = 1000;
export const WAKE_WORD_RESTART_MAX_MS = 10000;

export interface UseWakeWordOptions {
    /**
     * Extra gate from the voice orchestration: not muted, waiting in
     * `sleeping`, voice available and this tab holding the voice lock.
     */
    active?: () => boolean;
    /** Runs after a detection; the host opens the listening window here. */
    onWake?: () => void;
}

/**
 * Local wake word detection (RF-01, RNF-01a): a continuous Web Speech
 * session that only runs with the wake word enabled, a consent valid for the
 * current consent text (RF-22), a visible tab and the host gate open. No
 * audio reaches OpenAI here — the Realtime session is opened by the host only
 * after a detection. Restarts itself on `onend` with 1 s backoff doubling up
 * to 10 s; it never speaks.
 */
export function useWakeWord(options: UseWakeWordOptions = {}) {
    const preferences = usePreferencesStore();

    const supported = isSpeechRecognitionSupported();
    const listening = ref(false);

    let recognizer: WebSpeechRecognizer | null = null;
    let restartTimer: number | null = null;
    let backoffMs = WAKE_WORD_RESTART_BASE_MS;
    let running = false;

    function shouldRun(): boolean {
        return (
            preferences.wakeWordEnabled &&
            preferences.wakeWordConsentValid &&
            supported &&
            document.visibilityState !== 'hidden' &&
            (options.active?.() ?? true)
        );
    }

    function stopRecognition(): void {
        if (restartTimer !== null) {
            window.clearTimeout(restartTimer);
            restartTimer = null;
        }

        if (recognizer !== null) {
            recognizer.stop();
            recognizer = null;
        }

        running = false;
        listening.value = false;
    }

    function startRecognition(fromRestart = false): void {
        if (!shouldRun() || recognizer !== null) {
            return;
        }

        if (!fromRestart) {
            backoffMs = WAKE_WORD_RESTART_BASE_MS;
        }

        running = true;

        const instance = new WebSpeechRecognizer({
            onStart: () => {
                listening.value = true;
            },
            onPartial: (text) => {
                detect(text);
            },
            onFinal: (text) => {
                detect(text);
            },
            onError: () => {
                // The browser ended the session; onEnd schedules the restart.
            },
            onEnd: () => {
                recognizer = null;
                listening.value = false;

                if (running && shouldRun()) {
                    scheduleRestart();
                }
            },
        });

        recognizer = instance;
        instance.start({ lang: preferences.speechLocale, continuous: true, interimResults: true });
    }

    /** Exponential backoff: 1 s, 2 s, 4 s, ... capped at 10 s. */
    function scheduleRestart(): void {
        restartTimer = window.setTimeout(() => {
            restartTimer = null;
            startRecognition(true);
        }, backoffMs);

        backoffMs = Math.min(backoffMs * 2, WAKE_WORD_RESTART_MAX_MS);
    }

    function detect(text: string): void {
        if (!running || !shouldRun() || !WAKE_WORD_PATTERN.test(text)) {
            return;
        }

        // Stop the continuous session so the microphone is free for the window.
        stopRecognition();

        options.onWake?.();
    }

    /** Starts or stops the continuous session to match the current state. */
    function sync(): void {
        if (shouldRun()) {
            startRecognition();
        } else {
            stopRecognition();
        }
    }

    watch(
        [() => preferences.wakeWordEnabled, () => preferences.wakeWordConsentValid, () => options.active?.() ?? true],
        () => {
            sync();
        },
        { immediate: true },
    );

    document.addEventListener('visibilitychange', sync);

    function dispose(): void {
        stopRecognition();
        document.removeEventListener('visibilitychange', sync);
    }

    if (getCurrentInstance() !== null) {
        onBeforeUnmount(dispose);
    }

    return { listening, supported, dispose };
}
