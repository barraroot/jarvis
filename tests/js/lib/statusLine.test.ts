import { describe, expect, it } from 'vitest';
import { formatStatusLine, statusLineParts } from '@/lib/voice/statusLine';

describe('statusLine', () => {
    it('shows the countdown and the task count while listening', () => {
        expect(formatStatusLine({ phase: 'listening', available: true, countdownSeconds: 57, activeTaskCount: 1 })).toBe(
            'Ouvindo · 57s · 1 tarefa',
        );
    });

    it('pluralizes the task count', () => {
        expect(formatStatusLine({ phase: 'listening', available: true, countdownSeconds: 12, activeTaskCount: 2 })).toBe(
            'Ouvindo · 12s · 2 tarefas',
        );
    });

    it('hides the countdown while the assistant speaks', () => {
        expect(formatStatusLine({ phase: 'speaking', available: true, countdownSeconds: 40, activeTaskCount: 1 })).toBe(
            'Falando · 1 tarefa',
        );
    });

    it('hides the countdown while the operator speaks', () => {
        expect(formatStatusLine({ phase: 'user_speaking', available: true, countdownSeconds: 30, activeTaskCount: 0 })).toBe(
            'Você está falando…',
        );
    });

    it('waits for the wake word while sleeping', () => {
        expect(formatStatusLine({ phase: 'sleeping', available: true, countdownSeconds: null, activeTaskCount: 0 })).toBe(
            'Aguardando "Jarvis"',
        );
    });

    it('reports the text-only mode when voice is unavailable', () => {
        expect(formatStatusLine({ phase: 'listening', available: false, countdownSeconds: 30, activeTaskCount: 0 })).toBe(
            'Voz indisponível',
        );
    });

    it('omits the countdown while it is paused and the tasks when none is active', () => {
        expect(statusLineParts({ phase: 'listening', available: true, countdownSeconds: null, activeTaskCount: 0 })).toEqual({
            state: 'listening',
            seconds: null,
            tasks: null,
        });
        expect(formatStatusLine({ phase: 'listening', available: true, countdownSeconds: null, activeTaskCount: 0 })).toBe('Ouvindo');
    });
});
