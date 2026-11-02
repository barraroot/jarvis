import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
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
    it('renders a card per activity type with conversation and task links', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/activity?page=1': page([
                makeItem({ type: 'tool', id: 3, title: 'Enviar e-mail', conversation_id: 12, details: { duration_ms: 250 } }),
                makeItem({ type: 'agent', id: 1, title: 'Pesquisador', task_id: 7, details: { tool_call_count: 2 } }),
                makeItem({ type: 'approval', id: 5, title: 'Pagar boleto', status: 'denied', details: { decision_reason: 'valor alto' } }),
            ]),
            'GET /api/v1/tasks/7': { body: { data: { id: '7' } } },
        });

        const wrapper = await mountPage();

        const items = wrapper.findAll('[data-testid="activity-item"]');
        expect(items.map((item) => item.attributes('data-type'))).toEqual(['tool', 'agent', 'approval']);
        expect(wrapper.text()).toContain('jarvis://activity');
        expect(items[0]!.text()).toContain('Ferramenta');
        expect(items[0]!.text()).toContain('250 ms');
        expect(items[1]!.text()).toContain('2 chamadas de ferramenta');
        expect(items[2]!.text()).toContain('Decisão');
        expect(items[2]!.text()).toContain('valor alto');

        const conversationLink = wrapper.get('[data-testid="activity-conversation-link"]');
        expect(conversationLink.attributes('href')).toBe('/conversations/12');

        await wrapper.get('[data-testid="activity-task-link"]').trigger('click');
        await flushPromises();

        expect(calls.some((call) => call.url === '/api/v1/tasks/7')).toBe(true);
        expect(useTasksStore().detailOpen).toBe(true);

        wrapper.unmount();
    });

    it('reloads with period and type filters and paginates', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/activity?page=1': page([makeItem({ id: 1 })], { last_page: 2, total: 21 }),
            'GET /api/v1/activity?page=2': page([makeItem({ id: 2, title: 'Segunda página' })], { current_page: 2, last_page: 2, total: 21 }),
            'GET /api/v1/activity?from=2026-11-01&page=1': page([makeItem({ id: 3 })]),
            'GET /api/v1/activity?from=2026-11-01&type=approval&page=1': page([]),
        });

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Página 1 de 2');

        await wrapper.get('[data-testid="activity-next"]').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Segunda página');

        await wrapper.get('[data-testid="activity-filter-from"] input').setValue('2026-11-01');
        await flushPromises();

        await wrapper.get('[data-testid="activity-filter-type"] select').setValue('approval');
        await flushPromises();

        expect(calls.map((call) => call.url)).toEqual([
            '/api/v1/activity?page=1',
            '/api/v1/activity?page=2',
            '/api/v1/activity?from=2026-11-01&page=1',
            '/api/v1/activity?from=2026-11-01&type=approval&page=1',
        ]);
        expect(wrapper.find('[data-testid="activity-empty"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Nenhuma atividade no período.');

        wrapper.unmount();
    });
});
