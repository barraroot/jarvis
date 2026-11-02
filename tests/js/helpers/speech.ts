import { vi } from 'vitest';

/** Structural stand-in for `SpeechRecognitionEvent` (Web Speech API). */
export interface FakeResultEvent {
    resultIndex: number;
    results: {
        length: number;
        [index: number]: { isFinal: boolean; length: number; 0?: { transcript: string } } | undefined;
    };
}

export interface FakeRecognitionInstance {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    onstart: (() => void) | null;
    onresult: ((event: FakeResultEvent) => void) | null;
    onerror: ((event: { error?: unknown }) => void) | null;
    onend: (() => void) | null;
    stopped: boolean;
    aborted: boolean;
}

/** Registers a fake `window.SpeechRecognition` constructor and tracks its instances. */
export function stubSpeechRecognition(): { instances: FakeRecognitionInstance[] } {
    const instances: FakeRecognitionInstance[] = [];

    class FakeRecognition {
        lang = '';
        continuous = false;
        interimResults = false;
        onstart: (() => void) | null = null;
        onresult: ((event: FakeResultEvent) => void) | null = null;
        onerror: ((event: { error?: unknown }) => void) | null = null;
        onend: (() => void) | null = null;
        stopped = false;
        aborted = false;

        constructor() {
            instances.push(this as unknown as FakeRecognitionInstance);
        }

        start(): void {
            this.onstart?.();
        }

        stop(): void {
            this.stopped = true;
        }

        abort(): void {
            this.aborted = true;
        }
    }

    vi.stubGlobal('SpeechRecognition', FakeRecognition);

    return { instances };
}

export function makeResultEvent(results: Array<{ transcript: string; isFinal: boolean }>): FakeResultEvent {
    const list: FakeResultEvent['results'] = { length: results.length };

    results.forEach((result, index) => {
        list[index] = { isFinal: result.isFinal, length: 1, 0: { transcript: result.transcript } };
    });

    return { resultIndex: 0, results: list };
}
