import { describe, expect, it } from 'vitest';
import { bindVisualizationEvents, type MessageChannel } from '@/composables/useVisualizationEvents';
import { useVisualizationsStore } from '@/stores/visualizations';
import type { Visualization } from '@/types/models';

function fakeChannel(): MessageChannel & { emit(event: string, payload: unknown): void } {
    const listeners = new Map<string, (payload: unknown) => void>();

    return {
        listen(event, callback) {
            listeners.set(event, callback);
        },
        emit(event, payload) {
            listeners.get(`.${event}`)?.(payload);
        },
    };
}

function visualization(overrides: Partial<Visualization> = {}): Visualization {
    return {
        id: 1,
        task_id: 5,
        title: 'Busca',
        source: null,
        status: 'syncing',
        created_at: '2026-11-04T10:00:00Z',
        updated_at: '2026-11-04T10:00:00Z',
        closed_at: null,
        sections: [],
        ...overrides,
    };
}

describe('bindVisualizationEvents', () => {
    it('replaces the snapshot on .visualization.updated', () => {
        const channel = fakeChannel();
        const store = useVisualizationsStore();

        bindVisualizationEvents(channel);

        channel.emit('visualization.updated', { visualization: visualization() });
        expect(store.current?.status).toBe('syncing');

        channel.emit('visualization.updated', { visualization: visualization({ status: 'updated' }) });
        expect(store.current?.status).toBe('updated');
    });

    it('ignores events for a visualization closed locally', async () => {
        const channel = fakeChannel();
        const store = useVisualizationsStore();

        bindVisualizationEvents(channel);
        channel.emit('visualization.updated', { visualization: visualization() });
        await store.close();

        channel.emit('visualization.updated', { visualization: visualization({ status: 'updated' }) });

        expect(store.current).toBeNull();
    });

    it('ignores malformed payloads', () => {
        const channel = fakeChannel();
        const store = useVisualizationsStore();

        bindVisualizationEvents(channel);
        channel.emit('visualization.updated', null);
        channel.emit('visualization.updated', {});

        expect(store.current).toBeNull();
    });
});
