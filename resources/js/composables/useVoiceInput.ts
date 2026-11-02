import { computed, getCurrentInstance, onBeforeUnmount, ref } from 'vue';
import { translate } from '@/i18n';
import {
    isSpeechRecognitionSupported,
    WebSpeechRecognizer,
    type SpeechRecognizer,
} from '@/lib/voice/SpeechRecognizer';
import { useAssistantStore } from '@/stores/assistant';
import { useMessagesStore } from '@/stores/messages';
import { usePreferencesStore } from '@/stores/preferences';
import { useToastStore } from '@/stores/toast';

/**
 * Push-to-talk capture (US-4.1): the voice button or Tab starts the Web Speech
 * API, interim transcripts show in the footer, silence of
 * `silence_timeout_ms` ends the capture and sends the transcript with
 * `source: 'voice'`. A `getUserMedia` AnalyserNode feeds `JarvisCore.level`.
 * No audio ever leaves the browser — only the transcribed text is sent.
 */
export function useVoiceInput() {
    const assistant = useAssistantStore();
    const preferences = usePreferencesStore();
    const messages = useMessagesStore();
    const toasts = useToastStore();

    const partialText = ref('');
    const permissionDenied = ref(false);

    const supported = isSpeechRecognitionSupported();
    const listening = computed(() => assistant.state === 'LISTENING');
    const blocked = computed(() => !supported || permissionDenied.value);

    let recognizer: SpeechRecognizer | null = null;
    let silenceTimer: number | null = null;
    let stream: MediaStream | null = null;
    let audioContext: AudioContext | null = null;
    let analyser: AnalyserNode | null = null;
    let levelFrame: number | null = null;
    let finishing = false;
    let captureActive = false;
    let starting = false;

    function clearSilenceTimer(): void {
        if (silenceTimer !== null) {
            window.clearTimeout(silenceTimer);
            silenceTimer = null;
        }
    }

    /** Every new transcript resets the silence clock (US-4.1). */
    function scheduleSilenceCheck(): void {
        clearSilenceTimer();
        silenceTimer = window.setTimeout(() => {
            void finish();
        }, preferences.silenceTimeoutMs);
    }

    function releaseMicrophone(): void {
        if (levelFrame !== null) {
            window.cancelAnimationFrame(levelFrame);
            levelFrame = null;
        }

        if (audioContext !== null && typeof audioContext.close === 'function') {
            void audioContext.close().catch(() => undefined);
        }

        audioContext = null;
        analyser = null;
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        assistant.setAudioLevel(0);
    }

    /**
     * Drops the capture without sending (permission denial, `no-speech`,
     * capture errors).
     */
    async function abort(): Promise<void> {
        if (recognizer === null && !listening.value) {
            return;
        }

        finishing = true;
        captureActive = false;
        clearSilenceTimer();
        recognizer?.stop();
        recognizer = null;
        releaseMicrophone();
        partialText.value = '';

        if (listening.value) {
            assistant.setState('IDLE');
        }
    }

    /**
     * Ends the capture: the browser session stops and the transcript, when
     * there is one, is sent with `source: 'voice'`.
     */
    async function finish(): Promise<void> {
        if (finishing || !captureActive) {
            return;
        }

        finishing = true;
        captureActive = false;
        clearSilenceTimer();
        const text = partialText.value.trim();
        recognizer?.stop();
        recognizer = null;
        releaseMicrophone();
        partialText.value = '';

        if (listening.value) {
            assistant.setState('IDLE');
        }

        finishing = false;

        if (text !== '') {
            try {
                await messages.send(text, 'voice');
            } catch {
                // The messages store already surfaced the failure through toasts.
            }
        }
    }

    function handleRecognitionError(code: string): void {
        if (code === 'not-allowed' || code === 'service-not-allowed') {
            permissionDenied.value = true;
            toasts.push(translate('voice.permissionDenied'), { variant: 'warning' });
            void abort();

            return;
        }

        if (code !== 'unsupported' && code !== 'start-failed' && code !== 'no-speech' && code !== 'aborted') {
            toasts.push(translate('voice.captureError'), { variant: 'warning' });
        }

        void abort();
    }

    /** Pulls the microphone level for the core from a getUserMedia analyser. */
    async function acquireMicrophone(): Promise<void> {
        if (navigator.mediaDevices?.getUserMedia === undefined) {
            return;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (error) {
            const name = (error as { name?: unknown } | null)?.name;

            if (name === 'NotAllowedError' || name === 'SecurityError') {
                permissionDenied.value = true;
                toasts.push(translate('voice.permissionDenied'), { variant: 'warning' });
                await abort();
            }

            // Other failures (no device) keep capture running without the level animation.
            return;
        }

        const w = window as unknown as Record<string, unknown>;
        const AudioContextConstructor = (w.AudioContext ?? w.webkitAudioContext) as (new () => AudioContext) | undefined;

        if (AudioContextConstructor === undefined) {
            return;
        }

        try {
            audioContext = new AudioContextConstructor();
            analyser = audioContext.createAnalyser();
            analyser.fftSize = 256;
            audioContext.createMediaStreamSource(stream).connect(analyser);
        } catch {
            return;
        }

        const samples = new Uint8Array(analyser.fftSize);

        const measure = (): void => {
            if (analyser === null) {
                return;
            }

            analyser.getByteTimeDomainData(samples);

            let sum = 0;

            for (let index = 0; index < samples.length; index++) {
                const deviation = ((samples[index] ?? 128) - 128) / 128;
                sum += deviation * deviation;
            }

            assistant.setAudioLevel(Math.min(1, Math.sqrt(sum / samples.length) * 3));
            levelFrame = window.requestAnimationFrame(measure);
        };

        levelFrame = window.requestAnimationFrame(measure);
    }

    /**
     * Starts push-to-talk: cancels any spoken response, sets the core to
     * `LISTENING` and opens the recognition session.
     */
    async function start(): Promise<void> {
        if (starting || !supported || permissionDenied.value || assistant.state === 'SLEEPING' || listening.value || recognizer !== null) {
            return;
        }

        starting = true;

        try {
            await preferences.ensureLoaded();

            window.speechSynthesis?.cancel?.();

            finishing = false;
            captureActive = true;
            partialText.value = '';
            assistant.setState('LISTENING');

            const instance = new WebSpeechRecognizer({
                onPartial: (text) => {
                    partialText.value = text;
                    scheduleSilenceCheck();
                },
                onFinal: (text) => {
                    partialText.value = text;
                    scheduleSilenceCheck();
                },
                onError: (code) => {
                    handleRecognitionError(code);
                },
                onEnd: () => {
                    recognizer = null;

                    // Browsers end non-continuous sessions on silence; treat it as the finish line.
                    if (captureActive) {
                        void finish();
                    }
                },
            });

            recognizer = instance;
            instance.start({ lang: preferences.speechLocale });

            // The silence clock also covers a capture where nothing was heard.
            scheduleSilenceCheck();

            await acquireMicrophone();
        } finally {
            starting = false;
        }
    }

    /** Voice button behaviour: stop (and send) while capturing, start otherwise. */
    function toggle(): Promise<void> {
        if (listening.value) {
            return finish();
        }

        return start();
    }

    function onKeydown(event: KeyboardEvent): void {
        if (event.key === 'Escape' && listening.value) {
            void finish();
        }
    }

    document.addEventListener('keydown', onKeydown);

    function dispose(): void {
        captureActive = false;
        document.removeEventListener('keydown', onKeydown);
        clearSilenceTimer();
        recognizer?.stop();
        recognizer = null;
        releaseMicrophone();
    }

    if (getCurrentInstance() !== null) {
        onBeforeUnmount(dispose);
    }

    return { partialText, permissionDenied, supported, listening, blocked, start, finish, abort, toggle, dispose };
}
