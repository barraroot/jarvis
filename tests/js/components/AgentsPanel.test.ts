import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import AgentsPanel from '@/components/hud/AgentsPanel.vue';
import { useTaskTimelineStore } from '@/stores/taskTimeline';
import { useTasksStore } from '@/stores/tasks';
import type { TaskSummary } from '@/types/models';
import { mockFetch } from '../helpers/fetch';

function makeTask(overrides: Partial<TaskSummary> = {}): TaskSummary {
    return {
        id: '5',
        title: 'Procure no blog a aula de inglês sobre phrasal verbs e me mande o link',
        status: 'running',
        progress: 20,
        model: { name: 'GPT-5 mini' },
        started_at: '2026-11-04T10:00:00Z',
        queued_at: '2026-11-04T09:59:58Z',
        completed_at: null,
        ...overrides,
    };
}

describe('AgentsPanel', () => {
    it('shows an empty state without tasks', () => {
        const wrapper = mount(AgentsPanel);

        expect(wrapper.find('[data-testid="agents-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="agents-counter"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('shows 1 ATIVO, the card, a running timer and PARAR for a running task', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-04T10:01:05Z'));
        useTasksStore().upsert(makeTask());

        const wrapper = mount(AgentsPanel);
        const card = wrapper.get('[data-testid="agents-card-5"]');

        expect(wrapper.get('[data-testid="agents-counter"]').text()).toBe('1 ATIVO');
        expect(card.text()).toContain('TAREFA');
        expect(card.get('[data-testid="agents-card-model"]').text()).toBe('GPT-5 mini');
        expect(card.get('[data-testid="agents-card-description"]').classes()).toContain('line-clamp-2');
        expect(card.get('[data-testid="agents-card-stop"]').text()).toBe('PARAR');
        expect(card.get('[data-testid="agents-card-timer"]').text()).toBe('01:05');

        await vi.advanceTimersByTimeAsync(2000);
        expect(card.get('[data-testid="agents-card-timer"]').text()).toBe('01:07');

        wrapper.unmount();
    });

    it('highlights the current item and renders the live timeline with italic notes', async () => {
        useTasksStore().upsert(makeTask());
        const timeline = useTaskTimelineStore();

        timeline.applyToolEvent('started', {
            tool: 'search_posts',
            execution_id: 100,
            task_id: 5,
            agent_execution_id: 40,
            display_name: 'Beer And Code · search_posts',
            input_summary: 'query: phrasal verbs',
        });
        timeline.applyProgressNote({ execution_id: 40, progress_note: 'Encontrei três posts' });
        timeline.applyToolEvent('completed', { tool: 'search_posts', execution_id: 100, task_id: 5, display_name: 'x', output_bytes: 1536 });

        const wrapper = mount(AgentsPanel);
        const current = wrapper.get('[data-testid="agents-card-current"]');

        expect(current.text()).toContain('Beer And Code · search_posts');
        expect(current.text()).toContain('query: phrasal verbs');

        const entries = wrapper.findAll('[data-testid="timeline-entry"]');
        expect(entries.map((entry) => entry.attributes('data-kind'))).toEqual(['tool_started', 'progress_note', 'tool_completed']);
        expect(entries[1]?.get('[data-testid="timeline-entry-text"]').classes()).toContain('italic');
        expect(entries[2]?.text()).toContain('Resultado recebido 1,5 KB');

        wrapper.unmount();
    });

    it('shows CONCLUÍDO in green without PARAR once the task completed', () => {
        useTaskTimelineStore().applyTask(makeTask({ status: 'completed', completed_at: '2026-11-04T10:02:00Z' }));

        const wrapper = mount(AgentsPanel);
        const counter = wrapper.get('[data-testid="agents-counter"]');

        expect(counter.text()).toBe('CONCLUÍDO');
        expect(counter.classes()).toContain('text-success');
        expect(wrapper.find('[data-testid="agents-card-stop"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="agents-card-timer"]').text()).toBe('02:00');

        wrapper.unmount();
    });

    it('prefers FALHOU over CANCELADO over CONCLUÍDO', async () => {
        const timeline = useTaskTimelineStore();

        timeline.applyTask(makeTask({ id: '1', status: 'completed' }));
        timeline.applyTask(makeTask({ id: '2', status: 'cancelled' }));

        const wrapper = mount(AgentsPanel);
        expect(wrapper.get('[data-testid="agents-counter"]').text()).toBe('CANCELADO');

        timeline.applyTask(makeTask({ id: '3', status: 'failed', error: 'O conector do blog não respondeu.' }));
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[data-testid="agents-counter"]').text()).toBe('FALHOU');
        expect(wrapper.get('[data-testid="agents-card-3"]').get('[data-testid="agents-card-error"]').text()).toBe(
            'O conector do blog não respondeu.',
        );
        expect(wrapper.find('[data-testid="agents-card-stop"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('cancels with PARAR and shows the pt-BR message on the card when the API answers 409', async () => {
        const { calls } = mockFetch({ 'POST /api/v1/tasks/5/cancel': { status: 409, body: { message: 'Conflict' } } });
        useTasksStore().upsert(makeTask());

        const wrapper = mount(AgentsPanel);

        await wrapper.get('[data-testid="agents-card-stop"]').trigger('click');
        await flushPromises();

        expect(calls.filter((call) => call.url === '/api/v1/tasks/5/cancel')).toHaveLength(1);
        expect(wrapper.get('[data-testid="agents-card-stop-error"]').text()).toBe('A tarefa já terminou e não pode mais ser parada.');

        wrapper.unmount();
    });

    it('moves the card to cancelled after a successful PARAR', async () => {
        mockFetch({
            'POST /api/v1/tasks/5/cancel': { body: { data: makeTask({ status: 'cancelled', completed_at: '2026-11-04T10:00:30Z' }) } },
        });
        useTasksStore().upsert(makeTask());

        const wrapper = mount(AgentsPanel);

        await wrapper.get('[data-testid="agents-card-stop"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="agents-card-5"]').attributes('data-status')).toBe('cancelled');
        expect(wrapper.get('[data-testid="agents-counter"]').text()).toBe('CANCELADO');
        expect(wrapper.find('[data-testid="agents-card-stop"]').exists()).toBe(false);

        wrapper.unmount();
    });
});
