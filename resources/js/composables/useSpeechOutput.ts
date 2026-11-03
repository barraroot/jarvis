import { computed, getCurrentInstance, onBeforeUnmount, watch } from 'vue';
import { useAssistantStore } from '@/stores/assistant';
import type { MessageChannel } from '@/stores/messages';
import { usePreferencesStore } from '@/stores/preferences';
import type { Message } from '@/types/models';

/**
 * Spoken responses (US-4.2): reads the speakable text of
 * `assistant.response.completed` with `SpeechSynthesis` when
 * `spoken_responses_enabled` and the core is not SLEEPING. The core goes
 * `SPEAKING` on start, `IDLE` on end; `onboundary` pulses the level so the
 * core animates while speaking.
 */
export function useSpeechOutput() {
    const assistant = useAssistantStore();
    const preferences = usePreferencesStore();

    const speaking = computed(() => assistant.state === 'SPEAKING');
    let boundaryTimer: number | null = null;

    function synthesis(): SpeechSynthesis | null {
        if (typeof window === 'undefined' || !('speechSynthesis' in window)) {
            return null;
        }

        return window.speechSynthesis;
    }

    function utteranceConstructor(): (new (text: string) => SpeechSynthesisUtterance) | null {
        if (typeof window === 'undefined') {
            return null;
        }

        const constructor = (window as unknown as Record<string, unknown>).SpeechSynthesisUtterance;

        return typeof constructor === 'function' ? (constructor as new (text: string) => SpeechSynthesisUtterance) : null;
    }

    /**
     * The speakable text of a structured payload: `payload.speech` wins, then
     * `payload.text`. Tables/lists without a speakable summary are not read
     * raw (Phase 14 provides `speech`). A plain response uses its content.
     */
    function speakableText(message: Message): string {
        const payload = message.payload;

        if (payload !== null && typeof payload === 'object') {
            const speech = payload.speech;
            const text = payload.text;

            if (typeof speech === 'string' && speech.trim() !== '') {
                return speech;
            }

            if (typeof text === 'string' && text.trim() !== '') {
                return text;
            }

            return '';
        }

        return message.content;
    }

    /** Preferred voice by name, then the first voice of the speech locale. */
    function pickVoice(synth: SpeechSynthesis, locale: string, preferredName: string | null): SpeechSynthesisVoice | null {
        const voices = synth.getVoices();

        if (preferredName !== null && preferredName !== '') {
            const named = voices.find((voice) => voice.name === preferredName);

            if (named !== undefined) {
                return named;
            }
        }

        const localized = voices.find((voice) => voice.lang.toLowerCase().startsWith(locale.toLowerCase()));

        return localized ?? voices[0] ?? null;
    }

    function pulseLevel(): void {
        assistant.setAudioLevel(0.35 + Math.random() * 0.65);

        if (boundaryTimer !== null) {
            window.clearTimeout(boundaryTimer);
        }

        boundaryTimer = window.setTimeout(() => assistant.setAudioLevel(0), 350);
    }

    function clearLevel(): void {
        if (boundaryTimer !== null) {
            window.clearTimeout(boundaryTimer);
            boundaryTimer = null;
        }

        assistant.setAudioLevel(0);
    }

    /**
     * Speaks a completed response when spoken responses are enabled and the
     * core is not SLEEPING (US-4.2).
     */
    async function speak(message: Message): Promise<void> {
        await preferences.ensureLoaded();

        const synth = synthesis();
        const Utterance = utteranceConstructor();

        if (synth === null || Utterance === null) {
            return;
        }

        if (!preferences.spokenResponsesEnabled || assistant.state === 'SLEEPING') {
            return;
        }

        const text = speakableText(message).trim();

        if (text === '') {
            return;
        }

        synth.cancel();

        const utterance = new Utterance(text);
        utterance.lang = preferences.speechLocale;
        utterance.rate = preferences.speechRate;

        const voice = pickVoice(synth, preferences.speechLocale, preferences.voiceName);

        if (voice !== null) {
            utterance.voice = voice;
        }

        utterance.onstart = () => assistant.setState('SPEAKING');
        utterance.onend = () => {
            if (assistant.state === 'SPEAKING') {
                assistant.setState('IDLE');
            }

            clearLevel();
        };
        utterance.onerror = utterance.onend;
        utterance.onboundary = () => pulseLevel();

        synth.speak(utterance);
    }

    /** Immediate stop (US-4.3): the synthesis is cancelled, the core goes IDLE. */
    function stop(): void {
        synthesis()?.cancel();
        clearLevel();

        if (assistant.state === 'SPEAKING') {
            assistant.setState('IDLE');
        }
    }

    function pause(): void {
        synthesis()?.pause();
    }

    function resume(): void {
        synthesis()?.resume();
    }

    function onCompleted(payload: unknown): void {
        const message = (payload as { message?: Message | null } | null)?.message;

        if (message === undefined || message === null) {
            return;
        }

        void speak(message);
    }

    /** Binds the `assistant.response.completed` channel event (called by `useRealtimeConnection`). */
    function bind(channel: MessageChannel): void {
        channel.listen('.assistant.response.completed', onCompleted);
    }

    // Esc cancels the spoken response (US-4.3).
    function onKeydown(event: KeyboardEvent): void {
        if (event.key === 'Escape') {
            stop();
        }
    }

    document.addEventListener('keydown', onKeydown);

    // A new request (text or voice) interrupts the speech (US-4.3).
    watch(
        () => assistant.state,
        (state) => {
            if (state === 'THINKING' && speaking.value) {
                stop();
            }
        },
    );

    function dispose(): void {
        document.removeEventListener('keydown', onKeydown);
        clearLevel();
    }

    if (getCurrentInstance() !== null) {
        onBeforeUnmount(dispose);
    }

    return { speaking, speak, stop, pause, resume, bind, dispose };
}
