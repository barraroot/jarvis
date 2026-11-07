import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import ResultData from '@/components/results/ResultData.vue';
import ResultRenderer from '@/components/results/ResultRenderer.vue';
import ResultSearchResults from '@/components/results/ResultSearchResults.vue';
import ResultTable from '@/components/results/ResultTable.vue';
import SuggestedActions from '@/components/results/SuggestedActions.vue';
import { useMessagesStore } from '@/stores/messages';
import { useTasksStore } from '@/stores/tasks';
import type { Message } from '@/types/models';

function makeMessage(payload: Record<string, unknown> | null): Message {
    return {
        id: 'm1',
        conversation_id: 'c1',
        role: 'assistant',
        source: null,
        status: 'completed',
        content: 'conteúdo original',
        payload,
        ai_model_id: null,
        created_at: '2026-11-02T10:00:00.000000Z',
    };
}

describe('ResultRenderer', () => {
    it('renders the typed component for a table payload', () => {
        const wrapper = mount(ResultRenderer, {
            props: {
                message: makeMessage({
                    type: 'table',
                    columns: ['Cliente', 'Atraso'],
                    rows: [
                        ['Beta', 3],
                        ['ACME', 12],
                    ],
                    caption: 'Clientes',
                }),
            },
        });

        expect(wrapper.find('[data-testid="result-table"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="result-invalid-warning"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Clientes');
    });

    it('falls back to text with a warning when the payload was flagged invalid', () => {
        const wrapper = mount(ResultRenderer, {
            props: {
                message: makeMessage({ type: 'text', text: 'tabela quebrada', meta: { result_invalid: true } }),
            },
        });

        expect(wrapper.find('[data-testid="result-invalid-warning"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Resultado inválido');
    });

    it('renders nothing for messages without a payload', () => {
        const wrapper = mount(ResultRenderer, { props: { message: makeMessage(null) } });

        expect(wrapper.find('[data-testid="result-renderer"]').exists()).toBe(false);
    });

    it('renders suggested actions and sends the request as a text message', async () => {
        const messages = useMessagesStore();
        const send = vi.spyOn(messages, 'send').mockResolvedValue();

        const wrapper = mount(ResultRenderer, {
            props: {
                message: makeMessage({
                    type: 'table',
                    columns: ['A'],
                    rows: [['x']],
                    suggested_actions: [{ label: 'Enviar lembrete', request: 'Envie um lembrete à ACME' }],
                }),
            },
        });

        const action = wrapper.get('[data-testid="suggested-action-0"]');

        expect(wrapper.get('[data-testid="suggested-actions"]').text()).toContain('Enviar lembrete');
        await action.trigger('click');

        expect(send).toHaveBeenCalledWith('Envie um lembrete à ACME', 'text');
    });

    it('shows at most four suggested actions', () => {
        const actions = Array.from({ length: 6 }, (_, index) => ({ label: `Ação ${index}`, request: `Pedido ${index}` }));

        const wrapper = mount(SuggestedActions, { props: { actions } });

        expect(wrapper.findAll('[data-testid^="suggested-action-"]')).toHaveLength(4);
    });

    it('renders the task card and opens the task detail', async () => {
        const tasks = useTasksStore();
        const openDetail = vi.spyOn(tasks, 'openDetail').mockResolvedValue();

        const wrapper = mount(ResultRenderer, {
            props: {
                message: makeMessage({ type: 'task_card', task_id: 42, title: 'Auditoria ERP', status: 'running', progress: 60 }),
            },
        });

        expect(wrapper.find('[data-testid="result-task-card"]').exists()).toBe(true);
        await wrapper.get('[data-testid="result-task-card-open"]').trigger('click');

        expect(openDetail).toHaveBeenCalledWith('42');
    });
});

describe('ResultTable', () => {
    const payload = {
        type: 'table' as const,
        columns: ['Cliente', 'Atraso'],
        rows: [
            ['Beta', 3],
            ['ACME', 12],
        ],
        caption: 'Clientes em atraso',
    };

    it('sorts rows by the clicked column, ascending then descending then off', async () => {
        const wrapper = mount(ResultTable, { props: { payload } });

        await wrapper.get('[data-testid="result-table-sort-0"]').trigger('click');
        let cells = wrapper.findAll('tbody tr td');
        expect(cells[0]?.text()).toBe('ACME');
        expect(cells[1]?.text()).toBe('12');

        await wrapper.get('[data-testid="result-table-sort-0"]').trigger('click');
        cells = wrapper.findAll('tbody tr td');
        expect(cells[0]?.text()).toBe('Beta');

        await wrapper.get('[data-testid="result-table-sort-0"]').trigger('click');
        cells = wrapper.findAll('tbody tr td');
        expect(cells[0]?.text()).toBe('Beta');
    });

    it('sorts numerically when the column holds numbers', async () => {
        const wrapper = mount(ResultTable, { props: { payload } });

        await wrapper.get('[data-testid="result-table-sort-1"]').trigger('click');

        const cells = wrapper.findAll('tbody tr td');
        expect(cells[0]?.text()).toBe('Beta');
        expect(cells[1]?.text()).toBe('3');
        expect(cells[3]?.text()).toBe('12');
    });
});

describe('ResultSearchResults', () => {
    it('links only safe http(s) URLs and always shows the source', () => {
        const wrapper = mount(ResultSearchResults, {
            props: {
                payload: {
                    type: 'search_results',
                    query: 'laravel ai',
                    results: [
                        { title: 'Docs', url: 'https://laravel.com', snippet: 'SDK', source: 'laravel.com' },
                        { title: 'Ruim', url: 'javascript:alert(1)', snippet: 'x', source: 'modelo' },
                    ],
                },
            },
        });

        const anchors = wrapper.findAll('a');
        expect(anchors).toHaveLength(1);
        expect(anchors[0]?.attributes('href')).toBe('https://laravel.com');
        expect(anchors[0]?.attributes('rel')).toBe('noopener noreferrer');
        expect(wrapper.findAll('[data-testid="result-search-source"]')).toHaveLength(2);
    });
});

describe('ResultData', () => {
    it('highlights the value and draws one bar per series point', () => {
        const wrapper = mount(ResultData, {
            props: {
                payload: {
                    type: 'data',
                    label: 'Uso de CPU',
                    value: 63.5,
                    unit: '%',
                    series: { label: 'Últimos minutos', values: [10, 20, 15] },
                },
            },
        });

        expect(wrapper.get('[data-testid="result-data-value"]').text()).toBe('63.5');
        expect(wrapper.get('[data-testid="result-data-series"]').findAll('div[title]')).toHaveLength(3);
    });

    it('omits the series block when there is no series', () => {
        const wrapper = mount(ResultData, { props: { payload: { type: 'data', label: 'L', value: 1 } } });

        expect(wrapper.find('[data-testid="result-data-series"]').exists()).toBe(false);
    });
});

describe('ResultRenderer chamfer containers (UI-15)', () => {
    const payloads: Record<string, Record<string, unknown>> = {
        text: { type: 'text', text: 'Olá' },
        list: { type: 'list', title: 'Lista', items: [{ label: 'Item' }] },
        table: { type: 'table', columns: ['A'], rows: [['x']], caption: 'Tabela' },
        report: { type: 'report', title: 'Relatório', sections: [{ heading: 'Resumo', body: 'Texto' }] },
        search_results: { type: 'search_results', query: 'aula', results: [{ title: 'Aula', snippet: 'x', source: 'blog' }] },
        task_card: { type: 'task_card', task_id: 1, title: 'Tarefa', status: 'running', progress: 10 },
        data: { type: 'data', label: 'CPU', value: 10 },
        execution_status: { type: 'execution_status', entity: 'tool', name: 'busca', status: 'completed', message: 'ok' },
        document: { type: 'document', title: 'Doc', format: 'markdown', body: 'Corpo' },
    };

    it.each(Object.keys(payloads))('renders %s inside a chamfer-md container without rounded corners', (type) => {
        const wrapper = mount(ResultRenderer, { props: { message: makeMessage(payloads[type] ?? null) } });
        const testid = `result-${type.replace(/_/g, '-')}`;
        const element = wrapper.get(`[data-testid="${testid}"]`).element;

        const rounded = wrapper.findAll('[class*="rounded"]').filter((node) => node.attributes('data-dot') === undefined);

        expect(element.closest('.chamfer-md')).not.toBeNull();
        expect(rounded.map((node) => node.html())).toEqual([]);
    });

    it('renders suggested actions as chamfered chips', () => {
        const wrapper = mount(SuggestedActions, { props: { actions: [{ label: 'Enviar', request: 'Envie' }] } });

        expect(wrapper.get('[data-testid="suggested-action-0"]').classes()).toContain('chamfer-sm');
    });
});
