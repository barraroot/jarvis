import { describe, expect, it } from 'vitest';
import { bindToolEvents, type MessageChannel } from '@/composables/useToolEvents';
import { useToolsStore } from '@/stores/tools';

/** In-memory channel: stores callbacks keyed by event name. */
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

describe('bindToolEvents', () => {
    it('feeds the side panel with real tool.* statuses', () => {
        const channel = fakeChannel();
        const tools = useToolsStore();

        bindToolEvents(channel);

        channel.emit('tool.started', { tool: 'erp.list_overdue', execution_id: 10, status: 'running' });
        expect(tools.tools).toEqual([{ id: '10', name: 'erp.list_overdue', status: 'active' }]);

        channel.emit('tool.completed', { tool: 'erp.list_overdue', execution_id: 10, status: 'completed' });
        expect(tools.tools[0]?.status).toBe('idle');

        channel.emit('tool.failed', { tool: 'erp.list_overdue', execution_id: 10, status: 'timed_out' });
        expect(tools.tools[0]?.status).toBe('error');

        // A second tool row appears alongside the first.
        channel.emit('tool.started', { tool: 'rest.send_reminder', execution_id: 11, status: 'running' });
        expect(tools.tools).toHaveLength(2);
    });

    it('ignores malformed payloads', () => {
        const channel = fakeChannel();
        const tools = useToolsStore();

        bindToolEvents(channel);

        channel.emit('tool.started', {});
        channel.emit('tool.completed', null);

        expect(tools.tools).toEqual([]);
    });
});
