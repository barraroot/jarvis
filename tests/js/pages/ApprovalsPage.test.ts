import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ApprovalsPage from '@/pages/ApprovalsPage.vue';
import { useApprovalsStore } from '@/stores/approvals';
import type { ApprovalStatus, ApprovalSummary } from '@/types/models';
import { mockFetch } from '../helpers/fetch';

function makeApproval(id: string, status: ApprovalStatus = 'pending', overrides: Partial<ApprovalSummary> = {}): ApprovalSummary {
    return {
        id,
        status,
        tool: { identifier: `erp.tool_${id}`, name: `Ferramenta ${id}`, description: 'Descrição.' },
        effect: { is_write: true, is_sensitive: false, external: false },
        params: { key: 'value' },
        agent: null,
        requested_at: '2026-11-02T12:00:00+00:00',
        expires_at: new Date(Date.now() + 3600_000).toISOString(),
        decided_at: null,
        decision_reason: null,
        ...overrides,
    };
}

function historyPage(data: ApprovalSummary[], currentPage = 1, lastPage = 1) {
    return { body: { data, meta: { current_page: currentPage, last_page: lastPage } } };
}

const EMPTY_HISTORY = { 'GET /api/v1/approvals?page=1': historyPage([]) };

describe('ApprovalsPage', () => {
    it('lists only pending approvals from the API', async () => {
        mockFetch({
            'GET /api/v1/approvals?status=pending': {
                body: {
                    data: [makeApproval('1'), makeApproval('2')],
                },
            },
            ...EMPTY_HISTORY,
        });

        const wrapper = mount(ApprovalsPage);
        await flushPromises();

        expect(wrapper.findAll('[data-testid="approvals-item"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('Ferramenta 1');
        expect(wrapper.text()).toContain('Ferramenta 2');

        const store = useApprovalsStore();
        expect(store.loaded).toBe(true);

        wrapper.unmount();
    });

    it('shows the "Tudo em dia" empty state when nothing is pending', async () => {
        mockFetch({ 'GET /api/v1/approvals?status=pending': { body: { data: [] } }, ...EMPTY_HISTORY });

        const wrapper = mount(ApprovalsPage);
        await flushPromises();

        expect(wrapper.find('[data-testid="approvals-empty"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="empty-state-title"]').text()).toBe('Tudo em dia');
        expect(wrapper.get('[data-testid="empty-state-text"]').text()).toBe(
            'Nenhuma aprovação pendente. O JARVIS avisa quando precisar de você.',
        );

        wrapper.unmount();
    });

    it('shows the title and an intro without voice approval', async () => {
        mockFetch({ 'GET /api/v1/approvals?status=pending': { body: { data: [] } }, ...EMPTY_HISTORY });

        const wrapper = mount(ApprovalsPage);
        await flushPromises();

        expect(wrapper.get('[data-testid="approvals-page-title"]').text()).toBe('Aprovações');
        expect(wrapper.get('[data-testid="approvals-page-subtitle"]').text()).toContain('escrita');
        expect(wrapper.text()).not.toMatch(/pode mandar|por voz|Risco/i);

        wrapper.unmount();
    });

    it('renders pending cards before the decided history, keeping pending out of it', async () => {
        mockFetch({
            'GET /api/v1/approvals?status=pending': { body: { data: [makeApproval('1')] } },
            'GET /api/v1/approvals?page=1': historyPage([
                makeApproval('1'),
                makeApproval('2', 'approved', { agent: 'Pesquisador', decided_at: '2026-11-02T12:05:00+00:00' }),
                makeApproval('3', 'denied', { decision_reason: 'valor alto' }),
            ]),
        });

        const wrapper = mount(ApprovalsPage);
        await flushPromises();

        expect(wrapper.findAll('[data-testid="approval-card"]')).toHaveLength(1);

        const rows = wrapper.findAll('[data-testid="approvals-history-row"]');
        expect(rows).toHaveLength(2);
        expect(rows.map((row) => row.get('[data-testid="approvals-history-decision"]').text())).toEqual(['Aprovada', 'Negada']);
        expect(rows[0]!.text()).toContain('Ferramenta 2');
        expect(rows[0]!.text()).toContain('Pesquisador');
        expect(rows[1]!.get('[data-testid="approvals-history-reason"]').text()).toContain('valor alto');
        expect(wrapper.get('[data-testid="approvals-history"]').text()).not.toContain('Ferramenta 1');

        const headers = wrapper.findAll('[data-testid="approvals-history"] th').map((header) => header.text());
        expect(headers).toEqual(['Quando', 'Ação', 'Agente', 'Decisão']);
        expect(wrapper.text()).not.toContain('Decidido por');

        const html = wrapper.html();
        expect(html.indexOf('data-testid="approvals-pending"')).toBeLessThan(html.indexOf('data-testid="approvals-history"'));

        wrapper.unmount();
    });

    it('labels expired decisions', async () => {
        mockFetch({
            'GET /api/v1/approvals?status=pending': { body: { data: [] } },
            'GET /api/v1/approvals?page=1': historyPage([makeApproval('4', 'expired')]),
        });

        const wrapper = mount(ApprovalsPage);
        await flushPromises();

        expect(wrapper.get('[data-testid="approvals-history-decision"]').text()).toBe('Expirada');

        wrapper.unmount();
    });

    it('loads the next history page with "Carregar mais" and hides it on the last page', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/approvals?status=pending': { body: { data: [] } },
            'GET /api/v1/approvals?page=1': historyPage([makeApproval('2', 'approved')], 1, 2),
            'GET /api/v1/approvals?page=2': historyPage([makeApproval('3', 'denied')], 2, 2),
        });

        const wrapper = mount(ApprovalsPage);
        await flushPromises();

        const more = wrapper.get('[data-testid="approvals-history-more"]');
        expect(more.text()).toBe('Carregar mais');

        await more.trigger('click');
        await flushPromises();

        expect(calls.some((call) => call.url === '/api/v1/approvals?page=2')).toBe(true);
        expect(wrapper.findAll('[data-testid="approvals-history-row"]')).toHaveLength(2);
        expect(wrapper.find('[data-testid="approvals-history-more"]').exists()).toBe(false);

        wrapper.unmount();
    });
});
