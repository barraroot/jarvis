import { describe, expect, it, vi } from 'vitest';
import { useAssistantStore } from '@/stores/assistant';
import { useConnectionStore } from '@/stores/connection';

function silenceWarn(): ReturnType<typeof vi.spyOn> {
    return vi.spyOn(console, 'warn').mockImplementation(() => {});
}

describe('assistant store', () => {
    it('starts IDLE and accepts state changes', () => {
        const assistant = useAssistantStore();

        expect(assistant.state).toBe('IDLE');

        assistant.setState('THINKING');
        expect(assistant.state).toBe('THINKING');

        assistant.setState('IDLE');
        expect(assistant.state).toBe('IDLE');
    });

    it('lets DISCONNECTED from the connection store override everything', () => {
        const assistant = useAssistantStore();
        const connection = useConnectionStore();

        assistant.setState('LISTENING');
        connection.state = 'disconnected';

        expect(assistant.state).toBe('DISCONNECTED');

        assistant.setState('SPEAKING');
        expect(assistant.state).toBe('DISCONNECTED');

        // Reconnecting restores the previously requested state.
        connection.state = 'connected';
        expect(assistant.state).toBe('LISTENING');
    });

    it('blocks transitions while disconnected and logs them in dev', () => {
        const assistant = useAssistantStore();
        const connection = useConnectionStore();
        const warn = silenceWarn();

        connection.state = 'disconnected';
        assistant.setState('THINKING');

        expect(assistant.state).toBe('DISCONNECTED');
        expect(warn).toHaveBeenCalledWith(expect.stringContaining('THINKING'));
    });

    it('SLEEPING ignores every transition and only wake() leaves it', () => {
        const assistant = useAssistantStore();
        const warn = silenceWarn();

        assistant.sleep();
        expect(assistant.state).toBe('SLEEPING');

        assistant.setState('SPEAKING');
        expect(assistant.state).toBe('SLEEPING');
        expect(warn).toHaveBeenCalledWith(expect.stringContaining('ignored transition to SPEAKING'));

        assistant.setState('ERROR');
        expect(assistant.state).toBe('SLEEPING');

        assistant.wake();
        expect(assistant.state).toBe('IDLE');

        // wake() outside SLEEPING is a no-op.
        assistant.setState('THINKING');
        assistant.wake();
        expect(assistant.state).toBe('THINKING');
    });

    it('sleeps while disconnected and wakes back to the desired state', () => {
        const assistant = useAssistantStore();
        const connection = useConnectionStore();

        connection.state = 'disconnected';
        assistant.sleep();

        expect(assistant.state).toBe('DISCONNECTED');

        connection.state = 'connected';
        expect(assistant.state).toBe('SLEEPING');

        assistant.wake();
        expect(assistant.state).toBe('IDLE');
    });

    it('accepts server states from assistant.state.changed events and ignores junk', () => {
        const assistant = useAssistantStore();

        assistant.applyServerState('EXECUTING');
        expect(assistant.state).toBe('EXECUTING');

        assistant.applyServerState('KABOOM');
        expect(assistant.state).toBe('EXECUTING');

        assistant.applyServerState({ state: 'THINKING' });
        expect(assistant.state).toBe('EXECUTING');
    });

    it('clamps audioLevel between 0 and 1', () => {
        const assistant = useAssistantStore();

        assistant.setAudioLevel(1.4);
        expect(assistant.audioLevel).toBe(1);

        assistant.setAudioLevel(-0.2);
        expect(assistant.audioLevel).toBe(0);

        assistant.setAudioLevel(Number.NaN);
        expect(assistant.audioLevel).toBe(0);

        assistant.setAudioLevel(0.5);
        expect(assistant.audioLevel).toBe(0.5);
    });
});
