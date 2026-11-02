import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ApprovalsPage from '@/pages/ApprovalsPage.vue';
import { useApprovalsStore } from '@/stores/approvals';
import type { ApprovalStatus, ApprovalSummary } from '@/types/models';
import { mockFetch } from '../helpers/fetch';

function makeApproval(id: string, status: ApprovalStatus = 'pending'): ApprovalSummary {
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
    };
}

describe('ApprovalsPage', () => {
    it('lists only pending approvals from the API', async () => {
        mockFetch({
            'GET /api/v1/approvals?status=pending': {
                body: {
                    data: [makeApproval('1'), makeApproval('2')],
                },
            },
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

    it('shows the empty state when nothing is pending', async () => {
        mockFetch({ 'GET /api/v1/approvals?status=pending': { body: { data: [] } } });

        const wrapper = mount(ApprovalsPage);
        await flushPromises();

        expect(wrapper.find('[data-testid="approvals-empty"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Nenhuma aprovação pendente');

        wrapper.unmount();
    });
});
