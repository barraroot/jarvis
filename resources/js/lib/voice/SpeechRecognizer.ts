/**
 * Browser speech recognition abstraction (US-4.1). The SPA only ever sends
 * transcribed text to the backend — audio stays in the browser (Web Speech API).
 */

/** Handlers a recognizer host wires in; every one is optional. */
export interface SpeechRecognizerHandlers {
    /** The recognition session actually started (used to reset restart backoff). */
    onStart?: () => void;
    onPartial?: (text: string) => void;
    onFinal?: (text: string) => void;
    /** Machine-readable failure code, e.g. `not-allowed` or `unsupported`. */
    onError?: (code: string) => void;
    /** The browser ended the session (after stop, silence or an error). */
    onEnd?: () => void;
}

export interface SpeechRecognizerOptions {
    lang?: string;
    continuous?: boolean;
    interimResults?: boolean;
}

export interface SpeechRecognizer {
    readonly isSupported: boolean;
    start(options?: SpeechRecognizerOptions): void;
    stop(): void;
}

interface SpeechRecognitionAlternativeLike {
    transcript: string;
}

interface SpeechRecognitionResultLike {
    isFinal: boolean;
    length: number;
    [index: number]: SpeechRecognitionAlternativeLike;
}

interface SpeechRecognitionResultListLike {
    length: number;
    [index: number]: SpeechRecognitionResultLike;
}

interface SpeechRecognitionEventLike {
    resultIndex: number;
    results: SpeechRecognitionResultListLike;
}

interface SpeechRecognitionLike {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    onstart: (() => void) | null;
    onresult: ((event: SpeechRecognitionEventLike) => void) | null;
    onerror: ((event: { error?: unknown }) => void) | null;
    onend: (() => void) | null;
    start(): void;
    stop(): void;
    abort(): void;
}

type RecognitionConstructor = new () => SpeechRecognitionLike;

function recognitionConstructor(): RecognitionConstructor | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const w = window as unknown as Record<string, unknown>;
    const constructor = (w.SpeechRecognition ?? w.webkitSpeechRecognition) as unknown;

    return typeof constructor === 'function' ? (constructor as RecognitionConstructor) : null;
}

export function isSpeechRecognitionSupported(): boolean {
    return recognitionConstructor() !== null;
}

/**
 * Web Speech API recognizer (`window.SpeechRecognition` or the webkit
 * variant). A fresh instance per session: Chrome stops reusing instances.
 */
export class WebSpeechRecognizer implements SpeechRecognizer {
    private recognition: SpeechRecognitionLike | null = null;

    constructor(private readonly handlers: SpeechRecognizerHandlers) {}

    get isSupported(): boolean {
        return recognitionConstructor() !== null;
    }

    start(options: SpeechRecognizerOptions = {}): void {
        const Constructor = recognitionConstructor();

        if (Constructor === null) {
            this.handlers.onError?.('unsupported');

            return;
        }

        if (this.recognition !== null) {
            this.recognition.stop();
            this.recognition = null;
        }

        const recognition = new Constructor();
        recognition.lang = options.lang ?? 'pt-BR';
        recognition.continuous = options.continuous ?? false;
        recognition.interimResults = options.interimResults ?? true;
        recognition.onstart = () => this.handlers.onStart?.();
        recognition.onresult = (event) => this.onResult(event);
        recognition.onerror = (event) => this.handlers.onError?.(String(event.error ?? 'unknown'));
        recognition.onend = () => {
            this.recognition = null;
            this.handlers.onEnd?.();
        };
        this.recognition = recognition;

        try {
            recognition.start();
        } catch {
            this.recognition = null;
            this.handlers.onError?.('start-failed');
        }
    }

    stop(): void {
        if (this.recognition === null) {
            return;
        }

        try {
            this.recognition.stop();
        } catch {
            // The browser may already have ended the session.
        }
    }

    private onResult(event: SpeechRecognitionEventLike): void {
        let interim = '';
        let final = '';

        for (let index = event.resultIndex; index < event.results.length; index++) {
            const result = event.results[index];

            if (result === undefined) {
                continue;
            }

            const transcript = result[0]?.transcript ?? '';

            if (result.isFinal) {
                final += transcript;
            } else {
                interim += transcript;
            }
        }

        const finalText = final.trim();
        const partialText = interim.trim();

        if (finalText !== '') {
            this.handlers.onFinal?.(finalText);
        }

        if (partialText !== '') {
            this.handlers.onPartial?.(partialText);
        }
    }
}

/** Graceful fallback for browsers without the Web Speech API (US-4.1). */
export class UnsupportedRecognizer implements SpeechRecognizer {
    readonly isSupported = false;

    constructor(private readonly handlers: SpeechRecognizerHandlers) {}

    start(): void {
        this.handlers.onError?.('unsupported');
    }

    stop(): void {
        // Nothing to stop.
    }
}
