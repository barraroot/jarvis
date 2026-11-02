import { getCurrentInstance, onBeforeUnmount, ref, watch } from 'vue';
import { isSpeechRecognitionSupported, WebSpeechRecognizer } from '@/lib/voice/SpeechRecognizer';
import { useAssistantStore } from '@/stores/assistant';
import { usePreferencesStore } from '@/stores/preferences';

/** "Hey JARVIS" with the accepted variants (US-4.4). */
export const WAKE_WORD_PATTERN = /\b(hey|ei|e aí)\s+jarvis\b/i;

export const WAKE_WORD_RESTART_BASE_MS = 1000;
export const WAKE_WORD_RESTART_MAX_MS = 10000;

export interface UseWakeWordOptions {
    /** Runs after a detection; the host starts push-to-talk here. */
    onWake?: () => void;
}

/**
 * Continuous wake word recognition (US-4.4): active only when
 * `wake_word_enabled` and the Web Speech API exists. Uses its own
 * recognition session (`continuous=true`) that restarts itself on
 * `onend`/`onerror` with 1 s backoff doubling up to 10 s while the tab is
 * visible. Listening pauses during push-to-talk and SPEAKING so the assistant
 * never triggers itself.
 */
export function useWakeWord(options: UseWakeWordOptions = {}) {
    const assistant = useAssistantStore();
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
            supported &&
            document.visibilityState !== 'hidden' &&
            assistant.state !== 'LISTENING' &&
            assistant.state !== 'SPEAKING'
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

    /** Exponential backoff: 1 s, 2 s, 4 s, ... capped at 10 s (US-4.4). */
    function scheduleRestart(): void {
        restartTimer = window.setTimeout(() => {
            restartTimer = null;
            startRecognition(true);
        }, backoffMs);

        backoffMs = Math.min(backoffMs * 2, WAKE_WORD_RESTART_MAX_MS);
    }

    function detect(text: string): void {
        if (!WAKE_WORD_PATTERN.test(text) || assistant.state === 'LISTENING') {
            return;
        }

        // Stop the continuous session so its own stream cannot re-trigger.
        stopRecognition();

        window.speechSynthesis?.cancel?.();

        if (assistant.state === 'SLEEPING') {
            assistant.wake();
        }

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
        [() => preferences.wakeWordEnabled, () => assistant.state],
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
