import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import ActivityPage from '@/pages/ActivityPage.vue';
import { routes } from '@/router';
import { useTasksStore } from '@/stores/tasks';
import type { ActivityItem } from '@/types/models';
import { mockFetch } from '../helpers/fetch';

function makeItem(overrides: Partial<ActivityItem>): ActivityItem {
    return {
        type: 'agent',
        id: 1,
        occurred_at: '2026-11-14T10:00:00+00:00',
        status: 'completed',
        title: 'Pesquisador',
        conversation_id: null,
        task_id: null,
        details: {},
        ...overrides,
    };
}

function page(data: ActivityItem[], meta: Partial<{ current_page: number; last_page: number; total: number }> = {}) {
    return { body: { data, meta: { current_page: 1, last_page: 1, total: data.length, ...meta } } };
}

async function mountPage() {
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/activity');
    await router.isReady();

    const wrapper = mount(ActivityPage, { global: { plugins: [router] } });
    await flushPromises();

    return wrapper;
}

describe('ActivityPage', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders a table row per activity with type, event, duration, status and links', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/activity?page=1': page([
                makeItem({ type: 'tool', id: 3, title: 'Enviar e-mail', conversation_id: 12, details: { duration_ms: 250, identifier: 'gmail.send' } }),
                makeItem({ type: 'agent', id: 1, title: 'Pesquisador', task_id: 7, details: { tool_call_count: 2 } }),
                makeItem({ type: 'approval', id: 5, title: 'Pagar boleto', status: 'denied', details: { decision_reason: 'valor alto' } }),
            ]),
            'GET /api/v1/tasks/7': { body: { data: { id: '7' } } },
        });

        const wrapper = await mountPage();

        const headers = wrapper.findAll('thead th').map((header) => header.text());
        expect(headers).toEqual(['Hora', 'Tipo', 'Evento', 'Duração', 'Status']);

        const items = wrapper.findAll('[data-testid="activity-item"]');
        expect(items.map((item) => item.attributes('data-type'))).toEqual(['tool', 'agent', 'approval']);

        expect(items[0]!.get('[data-testid="activity-time"]').text()).toMatch(/^\d{2}:\d{2}:\d{2}$/);
        expect(items[0]!.get('[data-testid="activity-time"]').classes()).toContain('font-mono');
        expect(items[0]!.get('[data-testid="activity-type"]').text()).toBe('Ferramenta');
        expect(items[0]!.get('[data-testid="activity-event"]').text()).toContain('Enviar e-mail');
        expect(items[0]!.get('[data-testid="activity-identifier"]').text()).toBe('gmail.send');
        expect(items[0]!.get('[data-testid="activity-identifier"]').classes()).toContain('font-mono');
        expect(items[0]!.get('[data-testid="activity-duration"]').text().replace(/\s/g, ' ')).toBe('0,3 s');

        expect(items[1]!.get('[data-testid="activity-type"]').text()).toBe('Agente');
        expect(items[1]!.get('[data-testid="activity-duration"]').text()).toBe('—');

        expect(items[2]!.get('[data-testid="activity-type"]').text()).toBe('Decisão');
        expect(items[2]!.get('[data-testid="activity-duration"]').text()).toBe('—');
        expect(items[2]!.get('[data-testid="activity-event"]').text()).toContain('valor alto');
        expect(items[2]!.get('[data-testid="activity-status"] [data-status]').text()).toBe('Negada');
        expect(items[2]!.get('[data-testid="activity-status"] [data-status]').attributes('data-tone')).toBe('danger');

        const conversationLink = wrapper.get('[data-testid="activity-conversation-link"]');
        expect(conversationLink.attributes('href')).toBe('/conversations/12');

        await wrapper.get('[data-testid="activity-task-link"]').trigger('click');
        await flushPromises();

        expect(calls.some((call) => call.url === '/api/v1/tasks/7')).toBe(true);
        expect(useTasksStore().detailOpen).toBe(true);

        wrapper.unmount();
    });

    it('reloads with period and type chips and paginates', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date(2026, 10, 14, 15, 30));

        const { calls } = mockFetch({
            'GET /api/v1/activity?page=1': page([makeItem({ id: 1 })], { last_page: 2, total: 21 }),
            'GET /api/v1/activity?page=2': page([makeItem({ id: 2, title: 'Segunda página' })], { current_page: 2, last_page: 2, total: 21 }),
            'GET /api/v1/activity?from=2026-11-14&to=2026-11-14&page=1': page([makeItem({ id: 3 })]),
            'GET /api/v1/activity?from=2026-11-14&to=2026-11-14&type=approval&page=1': page([]),
        });

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Página 1 de 2');

        await wrapper.get('[data-testid="activity-next"]').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Segunda página');

        await wrapper.get('[data-testid="activity-period-today"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-testid="activity-period-today"]').attributes('aria-pressed')).toBe('true');

        await wrapper.get('[data-testid="activity-type-approval"]').trigger('click');
        await flushPromises();

        expect(calls.map((call) => call.url)).toEqual([
            '/api/v1/activity?page=1',
            '/api/v1/activity?page=2',
            '/api/v1/activity?from=2026-11-14&to=2026-11-14&page=1',
            '/api/v1/activity?from=2026-11-14&to=2026-11-14&type=approval&page=1',
        ]);
        expect(wrapper.find('[data-testid="activity-empty"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Nenhuma atividade no período.');

        wrapper.unmount();
    });

    it('maps the Tools chip to type=tool and toggles it off again', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/activity?page=1': page([]),
            'GET /api/v1/activity?type=tool&page=1': page([]),
        });

        const wrapper = await mountPage();

        await wrapper.get('[data-testid="activity-type-tool"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-testid="activity-type-tool"]').text()).toBe('Tools');
        expect(calls.at(-1)?.url).toBe('/api/v1/activity?type=tool&page=1');

        await wrapper.get('[data-testid="activity-type-tool"]').trigger('click');
        await flushPromises();
        expect(calls.at(-1)?.url).toBe('/api/v1/activity?page=1');

        wrapper.unmount();
    });

    it('maps "7 dias" and "Mês" to the last week and the calendar month', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date(2026, 10, 14, 9, 0));

        const { calls } = mockFetch({
            'GET /api/v1/activity?page=1': page([]),
            'GET /api/v1/activity?from=2026-11-08&to=2026-11-14&page=1': page([]),
            'GET /api/v1/activity?from=2026-11-01&to=2026-11-14&page=1': page([]),
        });

        const wrapper = await mountPage();

        await wrapper.get('[data-testid="activity-period-week"]').trigger('click');
        await flushPromises();
        expect(calls.at(-1)?.url).toBe('/api/v1/activity?from=2026-11-08&to=2026-11-14&page=1');

        await wrapper.get('[data-testid="activity-period-month"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-testid="activity-period-month"]').text()).toBe('Mês');
        expect(calls.at(-1)?.url).toBe('/api/v1/activity?from=2026-11-01&to=2026-11-14&page=1');

        wrapper.unmount();
    });

    it('shows no cost, latency or KPI columns', async () => {
        mockFetch({ 'GET /api/v1/activity?page=1': page([makeItem({ id: 1 })]) });

        const wrapper = await mountPage();

        for (const text of ['Custo', 'Latência', 'R$']) {
            expect(wrapper.text()).not.toContain(text);
        }

        const rounded = wrapper.findAll('[class*="rounded"]').filter((node) => node.attributes('data-dot') === undefined);
        expect(rounded.map((node) => node.html())).toEqual([]);

        wrapper.unmount();
    });
});
