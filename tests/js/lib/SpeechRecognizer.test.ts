import { describe, expect, it, vi } from 'vitest';
import {
    isSpeechRecognitionSupported,
    UnsupportedRecognizer,
    WebSpeechRecognizer,
} from '@/lib/voice/SpeechRecognizer';
import { makeResultEvent, stubSpeechRecognition } from '../helpers/speech';

describe('WebSpeechRecognizer', () => {
    it('reports support and configures the session with lang, continuous and interimResults', () => {
        const { instances } = stubSpeechRecognition();

        expect(isSpeechRecognitionSupported()).toBe(true);

        const onStart = vi.fn();
        const recognizer = new WebSpeechRecognizer({ onStart });

        recognizer.start({ lang: 'en-US', continuous: true, interimResults: true });

        const instance = instances[0];

        expect(recognizer.isSupported).toBe(true);
        expect(instance?.lang).toBe('en-US');
        expect(instance?.continuous).toBe(true);
        expect(instance?.interimResults).toBe(true);
        expect(onStart).toHaveBeenCalledTimes(1);
    });

    it('routes interim transcripts to onPartial and final ones to onFinal', () => {
        const { instances } = stubSpeechRecognition();
        const onPartial = vi.fn();
        const onFinal = vi.fn();
        const recognizer = new WebSpeechRecognizer({ onPartial, onFinal });

        recognizer.start();
        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'olá jarvis', isFinal: false }]));
        instances[0]?.onresult?.(makeResultEvent([{ transcript: 'bom dia', isFinal: true }]));

        expect(onPartial).toHaveBeenCalledWith('olá jarvis');
        expect(onFinal).toHaveBeenCalledWith('bom dia');
    });

    it('stops the browser session on stop() and reports the end once', () => {
        const { instances } = stubSpeechRecognition();
        const onEnd = vi.fn();
        const recognizer = new WebSpeechRecognizer({ onEnd });

        recognizer.start();
        recognizer.stop();
        expect(instances[0]?.stopped).toBe(true);

        instances[0]?.onend?.();
        expect(onEnd).toHaveBeenCalledTimes(1);
    });

    it('passes browser error codes through to onError', () => {
        const { instances } = stubSpeechRecognition();
        const onError = vi.fn();
        const recognizer = new WebSpeechRecognizer({ onError });

        recognizer.start();
        instances[0]?.onerror?.({ error: 'not-allowed' });

        expect(onError).toHaveBeenCalledWith('not-allowed');
    });

    it('reports not supported and fires onError when the API is missing', () => {
        const onError = vi.fn();
        const recognizer = new WebSpeechRecognizer({ onError });

        expect(recognizer.isSupported).toBe(false);
        expect(isSpeechRecognitionSupported()).toBe(false);

        recognizer.start();
        expect(onError).toHaveBeenCalledWith('unsupported');
    });
});

describe('UnsupportedRecognizer', () => {
    it('is a safe no-op that reports unsupported', () => {
        const onError = vi.fn();
        const recognizer = new UnsupportedRecognizer({ onError });

        expect(recognizer.isSupported).toBe(false);

        recognizer.start();
        recognizer.stop();

        expect(onError).toHaveBeenCalledWith('unsupported');
    });
});
