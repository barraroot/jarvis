import { describe, expect, it } from 'vitest';
import { bindAgentEvents } from '@/composables/useAgentEvents';
import { bindTaskEvents } from '@/composables/useTaskEvents';
import { bindToolEvents, type MessageChannel } from '@/composables/useToolEvents';
import { formatBytes, useTaskTimelineStore } from '@/stores/taskTimeline';
import { useTasksStore } from '@/stores/tasks';
import { mockFetch } from '../helpers/fetch';

function fakeChannel(): MessageChannel & { emit(event: string, payload: unknown): void } {
    const listeners = new Map<string, Array<(payload: unknown) => void>>();

    return {
        listen(event, callback) {
            listeners.set(event, [...(listeners.get(event) ?? []), callback]);
        },
        emit(event, payload) {
            listeners.get(`.${event}`)?.forEach((callback) => callback(payload));
        },
    };
}

function toolEvent(overrides: Record<string, unknown> = {}) {
    return {
        tool: 'mcp_blog_search',
        execution_id: 100,
        status: 'running',
        task_id: 5,
        agent_execution_id: 40,
        display_name: 'Beer And Code · search_posts',
        input_summary: 'query: aula de inglês',
        started_at: '2026-11-04T10:00:00Z',
        finished_at: null,
        ...overrides,
    };
}

describe('taskTimeline store', () => {
    it('formats sizes in B and KB with one pt-BR decimal', () => {
        expect(formatBytes(512)).toBe('512 B');
        expect(formatBytes(1536)).toBe('1,5 KB');
        expect(formatBytes(1024)).toBe('1,0 KB');
        expect(formatBytes(3 * 1024 * 1024)).toBe('3,0 MB');
    });

    it('records tool started, result received and failure per task in chronological order', () => {
        const timeline = useTaskTimelineStore();

        timeline.applyToolEvent('started', toolEvent());
        timeline.applyToolEvent('completed', toolEvent({ status: 'completed', output_bytes: 1536, finished_at: '2026-11-04T10:00:02Z' }));
        timeline.applyToolEvent('started', toolEvent({ execution_id: 101, display_name: 'web_fetch', input_summary: 'url: https://x' }));
        timeline.applyToolEvent('failed', toolEvent({ execution_id: 101, display_name: 'web_fetch', status: 'timed_out' }));

        const entries = timeline.entriesFor(5);

        expect(entries.map((entry) => [entry.kind, entry.text, entry.detail])).toEqual([
            ['tool_started', 'Beer And Code · search_posts', 'query: aula de inglês'],
            ['tool_completed', 'Resultado recebido 1,5 KB', null],
            ['tool_started', 'web_fetch', 'url: https://x'],
            ['tool_failed', 'Falha em web_fetch', 'Tempo esgotado'],
        ]);
        expect(entries.map((entry) => entry.sequence)).toEqual([...entries.map((entry) => entry.sequence)].sort((a, b) => a - b));
    });

    it('ignores tool events without a task and repeated events', () => {
        const timeline = useTaskTimelineStore();

        timeline.applyToolEvent('started', toolEvent({ task_id: null }));
        timeline.applyToolEvent('started', toolEvent());
        timeline.applyToolEvent('started', toolEvent());

        expect(timeline.entriesFor(5)).toHaveLength(1);
        expect(Object.keys(timeline.entries)).toEqual(['5']);
    });

    it('maps progress notes to the task through the agent execution, buffering early ones in arrival order', () => {
        const timeline = useTaskTimelineStore();

        timeline.applyProgressNote({ execution_id: 40, progress_note: 'Procurando no blog' });
        expect(timeline.entriesFor(5)).toHaveLength(0);
        expect(timeline.pendingNotes[40]).toEqual(['Procurando no blog']);

        timeline.applyToolEvent('started', toolEvent());
        timeline.applyProgressNote({ execution_id: 40, progress_note: 'Comparando resultados' });
        timeline.applyProgressNote({ execution_id: 40, progress_note: 'Comparando resultados' });

        expect(timeline.entriesFor(5).map((entry) => [entry.kind, entry.text])).toEqual([
            ['progress_note', 'Procurando no blog'],
            ['tool_started', 'Beer And Code · search_posts'],
            ['progress_note', 'Comparando resultados'],
        ]);
        expect(timeline.pendingNotes[40]).toBeUndefined();
    });

    it('reaches the parent task for notes of delegated sub-agents', () => {
        const timeline = useTaskTimelineStore();

        timeline.applyToolEvent('started', toolEvent());
        timeline.linkAgent({ execution_id: 41, parent_id: 40 });
        timeline.applyProgressNote({ execution_id: 41, progress_note: 'Subagente lendo o post' });

        expect(timeline.entriesFor(5).at(-1)?.text).toBe('Subagente lendo o post');
    });

    it('highlights the last tool started as current item', () => {
        const timeline = useTaskTimelineStore();

        timeline.applyToolEvent('started', toolEvent());
        expect(timeline.currentItem(5)).toEqual({ text: 'Beer And Code · search_posts', detail: 'query: aula de inglês', running: true });

        timeline.applyToolEvent('completed', toolEvent({ output_bytes: 10 }));
        expect(timeline.currentItem(5)?.running).toBe(false);
    });

    it('is fed by the tool, agent and task channels', () => {
        const channel = fakeChannel();
        const timeline = useTaskTimelineStore();

        bindToolEvents(channel);
        bindAgentEvents(channel);
        bindTaskEvents(channel);

        channel.emit('tool.started', toolEvent());
        channel.emit('agent.progress', { execution_id: 40, progress_note: 'Pensando' });
        channel.emit('tool.completed', toolEvent({ output_bytes: 1536 }));
        channel.emit('task.updated', { task: { id: '5', title: 'Buscar aula', status: 'completed', progress: 100 } });

        expect(timeline.entriesFor(5).map((entry) => entry.text)).toEqual([
            'Beer And Code · search_posts',
            'Pensando',
            'Resultado recebido 1,5 KB',
        ]);
        expect(timeline.snapshots['5']?.status).toBe('completed');
        expect(useTasksStore().tasks).toHaveLength(0);
    });

    it('cancels through the existing endpoint and keeps a pt-BR message on 409', async () => {
        const { calls } = mockFetch({
            'POST /api/v1/tasks/5/cancel': { status: 409, body: { message: 'Conflict' } },
            'POST /api/v1/tasks/6/cancel': { body: { data: { id: '6', title: 'Outra', status: 'cancelled', progress: 10 } } },
        });
        const timeline = useTaskTimelineStore();

        await timeline.stop('5');
        expect(calls.some((call) => call.method === 'POST' && call.url === '/api/v1/tasks/5/cancel')).toBe(true);
        expect(timeline.stopErrors['5']).toBe('A tarefa já terminou e não pode mais ser parada.');

        await timeline.stop('6');
        expect(timeline.stopErrors['6']).toBeUndefined();
        expect(timeline.snapshots['6']?.status).toBe('cancelled');
    });
});
