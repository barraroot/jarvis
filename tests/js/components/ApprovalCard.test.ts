import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import ApprovalCard from '@/components/hud/ApprovalCard.vue';
import { useApprovalsStore } from '@/stores/approvals';
import type { ApprovalSummary } from '@/types/models';
import { mockFetch } from '../helpers/fetch';

function makeApproval(overrides: Partial<ApprovalSummary> = {}): ApprovalSummary {
    const base: ApprovalSummary = {
        id: '7',
        status: 'pending',
        tool: { identifier: 'erp.send_payment_reminder', name: 'Enviar lembrete', description: 'Envia lembrete de pagamento.' },
        effect: { is_write: true, is_sensitive: false, external: true },
        params: { customer_ids: [42, 77] },
        agent: 'Automation',
        requested_at: '2026-11-02T12:00:00+00:00',
        expires_at: new Date(Date.now() + 3600_000).toISOString(),
        decided_at: null,
        decision_reason: null,
    };

    return { ...base, ...overrides };
}

describe('ApprovalCard', () => {
    it('renders tool, effect badges, params, agent and expiry countdown', async () => {
        const approval = makeApproval();

        const wrapper = mount(ApprovalCard, { props: { approval } });
        await flushPromises();

        expect(wrapper.text()).toContain('Enviar lembrete');
        expect(wrapper.text()).toContain('erp.send_payment_reminder');
        expect(wrapper.text()).toContain('"customer_ids"');
        expect(wrapper.text()).toContain('Automation');

        const effects = wrapper.findAll('[data-testid="approval-effect"]').map((node) => node.text());
        expect(effects).toEqual(['escrita', 'externa']);
        expect(wrapper.get('[data-testid="approval-countdown"]').text()).toContain('Expira em');

        wrapper.unmount();
    });

    it('approves through the store and renders the outcome', async () => {
        const approval = makeApproval();
        const store = useApprovalsStore();

        mockFetch({
            'POST /api/v1/approvals/7/approve': {
                body: { data: makeApproval({ status: 'approved', decided_at: '2026-11-02T13:00:00+00:00' }) },
            },
        });

        const wrapper = mount(ApprovalCard, { props: { approval } });
        await wrapper.get('[data-testid="approval-approve"]').trigger('click');
        await flushPromises();

        const updated = store.items.find((item) => item.id === '7');
        expect(updated?.status).toBe('approved');
        expect(wrapper.get('[data-testid="approval-status"]').text()).toBe('Aprovada');

        wrapper.unmount();
    });

    it('denies with an optional reason', async () => {
        const approval = makeApproval();
        const { calls } = mockFetch({
            'POST /api/v1/approvals/7/deny': {
                body: { data: makeApproval({ status: 'denied', decision_reason: 'Cliente já pagou' }) },
            },
        });

        const wrapper = mount(ApprovalCard, { props: { approval } });

        await wrapper.get('[data-testid="approval-deny"]').trigger('click');
        await wrapper.get('[data-testid="approval-deny-reason"] textarea').setValue('Cliente já pagou');
        await wrapper.get('[data-testid="approval-deny-confirm"]').trigger('click');
        await flushPromises();

        const denyCall = calls.find((call) => call.url === '/api/v1/approvals/7/deny');
        expect(denyCall).toBeDefined();
        expect(JSON.parse(String(denyCall?.init?.body))).toEqual({ reason: 'Cliente já pagou' });

        const store = useApprovalsStore();
        expect(store.items.find((item) => item.id === '7')?.status).toBe('denied');

        wrapper.unmount();
    });

    it('does not render decision buttons for a decided approval', async () => {
        const wrapper = mount(ApprovalCard, { props: { approval: makeApproval({ status: 'expired' }) } });
        await flushPromises();

        expect(wrapper.find('[data-testid="approval-actions"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="approval-status"]').text()).toBe('Expirada');

        wrapper.unmount();
    });

    it('stops the countdown ticker on unmount', () => {
        vi.useFakeTimers();
        const wrapper = mount(ApprovalCard, { props: { approval: makeApproval() } });
        const spy = vi.spyOn(window, 'clearInterval');
        wrapper.unmount();
        expect(spy).toHaveBeenCalled();
        spy.mockRestore();
    });
});
