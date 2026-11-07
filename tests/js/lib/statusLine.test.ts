import { describe, expect, it } from 'vitest';
import { countActiveTasks, formatStatusLine, statusLineParts, type StatusLineInput } from '@/lib/voice/statusLine';

function line(overrides: Partial<StatusLineInput>): string {
    return formatStatusLine({
        phase: 'sleeping',
        available: true,
        countdownSeconds: null,
        activeTaskCount: 0,
        ...overrides,
    });
}

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

    describe('the 9 labels', () => {
        it.each<[string, Partial<StatusLineInput>, string]>([
            ['listening', { phase: 'listening' }, 'Ouvindo'],
            ['speaking', { phase: 'speaking' }, 'Falando'],
            ['user_speaking', { phase: 'user_speaking' }, 'Você está falando…'],
            ['sleeping', { phase: 'sleeping' }, 'Aguardando "Jarvis"'],
            ['unavailable', { available: false }, 'Voz indisponível'],
            ['thinking', { phase: 'listening', assistantState: 'THINKING' }, 'Pensando'],
            ['executing', { phase: 'sleeping', assistantState: 'EXECUTING' }, 'Executando'],
            ['waitingApproval', { phase: 'listening', waitingApprovalCount: 1 }, 'Aguardando aprovação'],
            ['error', { phase: 'speaking', assistantState: 'ERROR' }, 'Erro'],
        ])('%s', (state, overrides, label) => {
            expect(statusLineParts({ phase: 'sleeping', available: true, countdownSeconds: null, activeTaskCount: 0, ...overrides }).state).toBe(
                state,
            );
            expect(line(overrides)).toBe(label);
        });
    });

    describe('precedence', () => {
        it('reports voice unavailable when the assistant is disconnected', () => {
            expect(line({ phase: 'listening', assistantState: 'DISCONNECTED' })).toBe('Voz indisponível');
        });

        it('keeps the operator and JARVIS speech above a pending approval', () => {
            expect(line({ phase: 'user_speaking', waitingApprovalCount: 1, activeTaskCount: 1 })).toBe('Você está falando…');
            expect(line({ phase: 'speaking', waitingApprovalCount: 1, activeTaskCount: 1 })).toBe('Falando · 1 tarefa');
        });

        it('shows a pending approval above thinking and executing', () => {
            expect(line({ phase: 'sleeping', assistantState: 'EXECUTING', waitingApprovalCount: 1, activeTaskCount: 2 })).toBe(
                'Aguardando aprovação · 2 tarefas',
            );
        });

        it('keeps the error above a pending approval', () => {
            expect(line({ assistantState: 'ERROR', waitingApprovalCount: 1 })).toBe('Erro');
        });

        it('shows thinking while listening without the countdown', () => {
            expect(line({ phase: 'listening', countdownSeconds: 20, assistantState: 'THINKING', activeTaskCount: 1 })).toBe(
                'Pensando · 1 tarefa',
            );
        });
    });

    describe('task segment', () => {
        it('counts only running and waiting_approval tasks', () => {
            expect(
                countActiveTasks([
                    { status: 'pending' },
                    { status: 'queued' },
                    { status: 'running' },
                    { status: 'waiting_approval' },
                    { status: 'completed' },
                    { status: 'failed' },
                    { status: 'cancelled' },
                ]),
            ).toBe(2);
        });

        it('omits the segment with 1 queued task and none running or waiting approval', () => {
            const activeTaskCount = countActiveTasks([{ status: 'queued' }]);

            expect(line({ phase: 'listening', countdownSeconds: 57, activeTaskCount })).toBe('Ouvindo · 57s');
        });

        it('omits the segment while waiting for the wake word, even with 2 running', () => {
            expect(line({ phase: 'sleeping', activeTaskCount: 2 })).toBe('Aguardando "Jarvis"');
        });

        it('omits the segment while the operator speaks, even with 2 running', () => {
            expect(line({ phase: 'user_speaking', activeTaskCount: 2 })).toBe('Você está falando…');
        });

        it('keeps the segment while executing', () => {
            expect(line({ assistantState: 'EXECUTING', activeTaskCount: 1 })).toBe('Executando · 1 tarefa');
        });
    });
});
