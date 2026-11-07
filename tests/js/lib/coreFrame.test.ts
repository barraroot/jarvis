import { describe, expect, it } from 'vitest';
import { resolveCoreFrame, type CoreFrame } from '@/lib/hud/coreFrame';
import type { VoicePhase } from '@/stores/voice';
import type { AssistantState } from '@/types/models';

type Row = [AssistantState, VoicePhase | null, CoreFrame];

describe('resolveCoreFrame', () => {
    it.each<Row>([
        // (1) assistant ERROR/DISCONNECTED always win.
        ['ERROR', 'speaking', 'ERROR'],
        ['ERROR', 'user_speaking', 'ERROR'],
        ['ERROR', null, 'ERROR'],
        ['DISCONNECTED', 'listening', 'DISCONNECTED'],
        ['DISCONNECTED', 'speaking', 'DISCONNECTED'],
        ['DISCONNECTED', null, 'DISCONNECTED'],
        // (2) operator / JARVIS speech.
        ['THINKING', 'user_speaking', 'LISTENING_SPEECH'],
        ['EXECUTING', 'speaking', 'SPEAKING'],
        ['IDLE', 'user_speaking', 'LISTENING_SPEECH'],
        ['IDLE', 'speaking', 'SPEAKING'],
        // (3) THINKING/EXECUTING over sleeping, listening or no voice.
        ['THINKING', 'listening', 'THINKING'],
        ['THINKING', 'sleeping', 'THINKING'],
        ['THINKING', null, 'THINKING'],
        ['EXECUTING', 'sleeping', 'EXECUTING'],
        ['EXECUTING', 'listening', 'EXECUTING'],
        ['EXECUTING', null, 'EXECUTING'],
        // (4) the voice phase decides.
        ['IDLE', 'sleeping', 'SLEEPING'],
        ['IDLE', 'listening', 'LISTENING'],
        ['LISTENING', 'sleeping', 'SLEEPING'],
        ['SPEAKING', 'listening', 'LISTENING'],
        ['SLEEPING', 'listening', 'LISTENING'],
    ])('%s + %s → %s', (assistantState, voicePhase, frame) => {
        expect(resolveCoreFrame({ assistantState, voicePhase }).frame).toBe(frame);
    });

    it('sleeps labelled as voice unavailable when IDLE without a voice phase', () => {
        expect(resolveCoreFrame({ assistantState: 'IDLE', voicePhase: null })).toEqual({
            frame: 'SLEEPING',
            voiceUnavailable: true,
        });
    });

    it('does not flag voice unavailable while the voice sleeps normally', () => {
        expect(resolveCoreFrame({ assistantState: 'IDLE', voicePhase: 'sleeping' })).toEqual({
            frame: 'SLEEPING',
            voiceUnavailable: false,
        });
    });

    it('does not flag voice unavailable on frames other than SLEEPING', () => {
        expect(resolveCoreFrame({ assistantState: 'THINKING', voicePhase: null }).voiceUnavailable).toBe(false);
        expect(resolveCoreFrame({ assistantState: 'ERROR', voicePhase: null }).voiceUnavailable).toBe(false);
    });
});
