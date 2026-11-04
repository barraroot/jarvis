import { describe, expect, it } from 'vitest';
import { useVisualizationsStore } from '@/stores/visualizations';
import type { Visualization } from '@/types/models';
import { mockFetch } from '../helpers/fetch';

function makeVisualization(overrides: Partial<Visualization> = {}): Visualization {
    return {
        id: 1,
        task_id: 5,
        title: 'Busca no blog',
        source: 'Beer And Code',
        status: 'syncing',
        created_at: '2026-11-04T10:00:00Z',
        updated_at: '2026-11-04T10:00:00Z',
        closed_at: null,
        sections: [{ key: 'question', position: 1, label: 'PERGUNTA', type: 'question', headline: 'Aula de inglês' }],
        ...overrides,
    };
}

describe('visualizations store', () => {
    it('restore loads the open visualization (CT-08)', async () => {
        mockFetch({ 'GET /api/v1/visualizations/current': { body: { data: makeVisualization({ id: 9 }) } } });
        const store = useVisualizationsStore();

        await store.restore();

        expect(store.current?.id).toBe(9);
        expect(store.isOpen).toBe(true);
    });

    it('restore keeps the panel closed when there is no open visualization', async () => {
        mockFetch({ 'GET /api/v1/visualizations/current': { body: { data: null } } });
        const store = useVisualizationsStore();

        await store.restore();

        expect(store.current).toBeNull();
    });

    it('apply replaces the whole snapshot', () => {
        const store = useVisualizationsStore();

        store.apply(makeVisualization());
        store.apply(
            makeVisualization({
                status: 'updated',
                sections: [
                    { key: 'question', position: 1, label: 'PERGUNTA', type: 'question', headline: 'Melhor aula' },
                    { key: 'results', position: 2, label: 'RESULTADOS', type: 'results', status: 'updated', items: [] },
                ],
            }),
        );

        expect(store.current?.status).toBe('updated');
        expect(store.current?.sections).toHaveLength(2);
    });

    it('close marks it closed via CT-09 and ignores later events for that id', async () => {
        const { calls } = mockFetch({
            'POST /api/v1/visualizations/1/close': { body: { data: makeVisualization({ closed_at: '2026-11-04T10:05:00Z' }) } },
        });
        const store = useVisualizationsStore();
        store.apply(makeVisualization());

        await store.close();

        expect(calls.some((call) => call.method === 'POST' && call.url === '/api/v1/visualizations/1/close')).toBe(true);
        expect(store.current).toBeNull();

        store.apply(makeVisualization({ status: 'updated' }));
        expect(store.current).toBeNull();

        // A follow-up after closing creates a different visualization, which opens.
        store.apply(makeVisualization({ id: 2 }));
        expect(store.current?.id).toBe(2);
    });

    it('ignores malformed payloads', () => {
        const store = useVisualizationsStore();

        store.apply(null);
        store.apply({ id: 'x' });

        expect(store.current).toBeNull();
    });
});
