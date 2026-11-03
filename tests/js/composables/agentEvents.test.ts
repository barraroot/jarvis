import { describe, expect, it, vi } from 'vitest';
import { bindAgentEvents, type MessageChannel } from '@/composables/useAgentEvents';
import { useActivityStore } from '@/stores/activity';
import { useAgentsStore } from '@/stores/agents';

/** In-memory channel; like Echo, a `broadcastAs` event only reaches its `.`-prefixed listener. */
function fakeChannel(): MessageChannel & { emit(event: string, payload: unknown): void; listeners: Map<string, (payload: unknown) => void> } {
    const listeners = new Map<string, (payload: unknown) => void>();

    return {
        listeners,
        listen(event, callback) {
            listeners.set(event, callback);
        },
        emit(event, payload) {
            listeners.get(`.${event}`)?.(payload);
        },
    };
}

describe('bindAgentEvents', () => {
    it('moves runs between the active list and the history with duration', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-02T12:00:00Z'));

        const channel = fakeChannel();
        const agents = useAgentsStore();
        const activity = useActivityStore();

        agents.clear();
        activity.clear();

        bindAgentEvents(channel);

        channel.emit('agent.started', { execution_id: 7, agent: 'research', depth: 0, parent_id: null, status: 'running' });

        expect(agents.agents).toHaveLength(1);
        expect(agents.agents[0]).toMatchObject({ execution_id: 7, agent: 'research', status: 'running' });

        channel.emit('agent.progress', { execution_id: 7, progress_note: 'Chamando ferramenta time.current' });
        expect(agents.agents[0]?.progress_note).toBe('Chamando ferramenta time.current');

        vi.setSystemTime(new Date('2026-11-02T12:00:04Z'));

        channel.emit('agent.completed', { execution_id: 7, agent: 'research', depth: 0, status: 'completed' });

        expect(agents.agents).toHaveLength(0);
        expect(agents.history).toHaveLength(1);
        expect(agents.history[0]).toMatchObject({ execution_id: 7, agent: 'research', status: 'completed' });
        expect(agents.history[0]?.duration_ms).toBe(4000);

        // The terminal feed got a line per event.
        expect(activity.entries.map((entry) => entry.text)).toEqual(['Agente research concluído', 'Agente research iniciou']);

        vi.useRealTimers();
    });

    it('marks failed runs and keeps the delegation depth', () => {
        const channel = fakeChannel();
        const agents = useAgentsStore();

        agents.clear();

        bindAgentEvents(channel);

        channel.emit('agent.started', { execution_id: 8, agent: 'developer', depth: 1, parent_id: 7, status: 'running' });
        channel.emit('agent.failed', { execution_id: 8, agent: 'developer', depth: 1, parent_id: 7, status: 'timed_out' });

        expect(agents.agents).toHaveLength(0);
        expect(agents.history[0]).toMatchObject({
            execution_id: 8,
            agent: 'developer',
            depth: 1,
            parent_id: 7,
            status: 'timed_out',
        });
    });

    it('ignores malformed payloads', () => {
        const channel = fakeChannel();
        const agents = useAgentsStore();
        const activity = useActivityStore();

        agents.clear();
        activity.clear();

        bindAgentEvents(channel);

        channel.emit('agent.started', {});
        channel.emit('agent.completed', null);
        channel.emit('agent.failed', { execution_id: 'nope' });

        expect(agents.agents).toEqual([]);
        expect(agents.history).toEqual([]);
        expect(activity.entries).toEqual([]);
    });
});
