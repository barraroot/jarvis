import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import AgentsPanel from '@/components/hud/AgentsPanel.vue';
import { useToastStore } from '@/stores/toast';
import { useTaskTimelineStore } from '@/stores/taskTimeline';
import { useTasksStore } from '@/stores/tasks';
import type { TaskStatus, TaskSummary } from '@/types/models';
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
        expect(counter.classes()).toContain('text-success-400');
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

    it('gives each of the 7 statuses its own color class', () => {
        const statuses: TaskStatus[] = ['pending', 'queued', 'running', 'waiting_approval', 'completed', 'failed', 'cancelled'];
        const timeline = useTaskTimelineStore();

        statuses.forEach((status, index) => timeline.applyTask(makeTask({ id: String(index + 1), status })));

        const wrapper = mount(AgentsPanel);
        const colorClasses = statuses.map((status, index) => {
            const card = wrapper.get(`[data-testid="agents-card-${index + 1}"]`);

            expect(card.attributes('data-status')).toBe(status);
            expect(card.classes()).toContain(`agents-status--${status}`);

            return card
                .classes()
                .filter((name) => name.startsWith('agents-status--') || name.startsWith('text-'))
                .sort()
                .join(' ');
        });

        expect(new Set(colorClasses).size).toBe(7);
        expect(wrapper.get('[data-testid="agents-card-3"]').classes()).toContain('text-cyan-300');
        expect(wrapper.get('[data-testid="agents-card-4"]').classes()).toContain('text-amber-400');
        expect(wrapper.get('[data-testid="agents-card-5"]').classes()).toContain('text-success-400');
        expect(wrapper.get('[data-testid="agents-card-6"]').classes()).toContain('text-danger-400');

        wrapper.unmount();
    });

    it('fades the timeline glow in 1500 ms and never loops the completed ring', () => {
        const root = process.cwd();
        const timelineSource = readFileSync(join(root, 'resources/js/components/hud/TaskTimeline.vue'), 'utf8');
        const panelSource = readFileSync(join(root, 'resources/js/components/hud/AgentsPanel.vue'), 'utf8');
        const css = readFileSync(join(root, 'resources/css/app.css'), 'utf8');

        expect(css).toMatch(/--dur-glow:\s*1500ms/);
        expect(timelineSource).toMatch(/timeline-entry-glow var\(--dur-glow\) ease-out 1/);

        const ringRule = /\.agents-status__icon--ring::after\s*\{([^}]*)\}/.exec(panelSource)?.[1] ?? '';
        expect(ringRule).toContain('animation:');
        expect(ringRule).not.toContain('infinite');
    });

    it('slides new timeline lines in with the glow class and keeps aria-live polite', () => {
        useTasksStore().upsert(makeTask());
        useTaskTimelineStore().applyToolEvent('started', { tool: 'search_posts', execution_id: 100, task_id: 5, display_name: 'Blog · search_posts' });

        const wrapper = mount(AgentsPanel);

        expect(wrapper.get('[data-testid="task-timeline"]').attributes('aria-live')).toBe('polite');
        expect(wrapper.get('[data-testid="timeline-entry"]').classes()).toContain('timeline-entry--enter');

        wrapper.unmount();
    });

    it('shows a delegation line A → B with the delegation label', () => {
        useTasksStore().upsert(makeTask({ agent: { slug: 'personal-assistant', name: 'Assistente' } }));
        useTaskTimelineStore().applyToolEvent('started', {
            tool: 'delegate.research',
            execution_id: 101,
            task_id: 5,
            display_name: 'Delegar para Pesquisa',
        });

        const wrapper = mount(AgentsPanel);
        const delegation = wrapper.get('[data-testid="timeline-entry-delegation"]');

        expect(delegation.text()).toBe('Assistente → Pesquisa');
        expect(delegation.attributes('aria-label')).toBe('Delegação de Assistente para Pesquisa');
        expect(wrapper.get('[data-testid="agents-card-model"]').text()).toBe('GPT-5 mini · Assistente');

        wrapper.unmount();
    });

    it('shows the failure state with tool, error, Tentar de novo and Ver log only for failed tasks', () => {
        const timeline = useTaskTimelineStore();
        timeline.applyToolEvent('started', { tool: 'search_posts', execution_id: 100, task_id: 7, display_name: 'Blog · search_posts' });
        timeline.applyTask(makeTask({ id: '7', status: 'failed', error: 'O conector do blog não respondeu.' }));
        useTasksStore().upsert(makeTask({ id: '8', status: 'running' }));

        const wrapper = mount(AgentsPanel);
        const failed = wrapper.get('[data-testid="agents-card-7"]');

        expect(failed.get('[data-testid="agents-card-failure-title"]').text()).toBe('FALHOU');
        expect(failed.get('[data-testid="agents-card-failure-tool"]').text()).toBe('Blog · search_posts');
        expect(failed.get('[data-testid="agents-card-error"]').text()).toBe('O conector do blog não respondeu.');
        expect(failed.get('[data-testid="agents-card-retry"]').text()).toBe('Tentar de novo');
        expect(failed.get('[data-testid="agents-card-view-log"]').text()).toBe('Ver log');

        const running = wrapper.get('[data-testid="agents-card-8"]');
        expect(running.find('[data-testid="agents-card-retry"]').exists()).toBe(false);
        expect(running.find('[data-testid="agents-card-view-log"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('Tentar de novo calls tasks.retry once and Ver log opens the detail', async () => {
        useTaskTimelineStore().applyTask(makeTask({ id: '7', status: 'failed', error: 'Falhou' }));
        const tasks = useTasksStore();
        const retry = vi.spyOn(tasks, 'retry').mockResolvedValue(undefined);
        const openDetail = vi.spyOn(tasks, 'openDetail').mockResolvedValue(undefined);

        const wrapper = mount(AgentsPanel);

        await wrapper.get('[data-testid="agents-card-retry"]').trigger('click');
        expect(retry).toHaveBeenCalledTimes(1);
        expect(retry).toHaveBeenCalledWith('7');

        await wrapper.get('[data-testid="agents-card-view-log"]').trigger('click');
        expect(openDetail).toHaveBeenCalledTimes(1);
        expect(openDetail).toHaveBeenCalledWith('7');

        wrapper.unmount();
    });

    it('turns a 409 on retry into a toast with the server message (RF-07)', async () => {
        const { calls } = mockFetch({
            'POST /api/v1/tasks/7/retry': { status: 409, body: { message: 'A tarefa já está em execução.' } },
        });
        useTaskTimelineStore().applyTask(makeTask({ id: '7', status: 'failed', error: 'Falhou' }));

        const wrapper = mount(AgentsPanel);

        await wrapper.get('[data-testid="agents-card-retry"]').trigger('click');
        await flushPromises();

        expect(calls.filter((call) => call.url === '/api/v1/tasks/7/retry')).toHaveLength(1);
        expect(useToastStore().toasts.map((toast) => toast.message)).toContain('A tarefa já está em execução.');

        wrapper.unmount();
    });

    it('falls back to the network message when the retry error has no server message', async () => {
        mockFetch({});
        vi.stubGlobal('fetch', vi.fn(async () => Promise.reject(new TypeError('offline'))));
        useTaskTimelineStore().applyTask(makeTask({ id: '7', status: 'failed', error: 'Falhou' }));

        const wrapper = mount(AgentsPanel);

        await wrapper.get('[data-testid="agents-card-retry"]').trigger('click');
        await flushPromises();

        expect(useToastStore().toasts.map((toast) => toast.message)).toContain('Falha de rede. Verifique sua conexão.');

        wrapper.unmount();
    });
});
