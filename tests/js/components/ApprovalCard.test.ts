import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
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
        expect(effects).toEqual(['Escrita', 'Externa']);
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

    it('derives exactly the Escrita and Externa chips from the effect flags', () => {
        const wrapper = mount(ApprovalCard, {
            props: { approval: makeApproval({ effect: { is_write: true, is_sensitive: false, external: true } }) },
        });

        expect(wrapper.findAll('[data-testid="approval-effect"]').map((node) => node.text())).toEqual(['Escrita', 'Externa']);

        wrapper.unmount();
    });

    it('shows Sensível alone and no chip row without effects', () => {
        const sensitive = mount(ApprovalCard, {
            props: { approval: makeApproval({ effect: { is_write: false, is_sensitive: true, external: false } }) },
        });
        expect(sensitive.findAll('[data-testid="approval-effect"]').map((node) => node.text())).toEqual(['Sensível']);
        sensitive.unmount();

        const none = mount(ApprovalCard, {
            props: { approval: makeApproval({ effect: { is_write: false, is_sensitive: false, external: false } }) },
        });
        expect(none.find('[data-testid="approval-effects"]').exists()).toBe(false);
        none.unmount();
    });

    it('shows agent, tool name, human description and mono params without risk or voice approval text', () => {
        const wrapper = mount(ApprovalCard, { props: { approval: makeApproval() } });

        expect(wrapper.get('[data-testid="approval-agent"]').text()).toContain('Automation');
        expect(wrapper.get('[data-testid="approval-tool"]').text()).toBe('Enviar lembrete');
        expect(wrapper.get('[data-testid="approval-description"]').text()).toBe('Envia lembrete de pagamento.');
        expect(wrapper.get('[data-testid="approval-params"]').classes()).toContain('font-mono');
        expect(wrapper.text()).not.toContain('Risco');
        expect(wrapper.text()).not.toContain('pode mandar');

        wrapper.unmount();
    });

    it('renders Aprovar as solid primary and Negar as outline', () => {
        const wrapper = mount(ApprovalCard, { props: { approval: makeApproval() } });

        const approve = wrapper.get('[data-testid="approval-approve"]');
        const deny = wrapper.get('[data-testid="approval-deny"]');

        expect(approve.text()).toBe('Aprovar');
        expect(approve.classes()).toEqual(expect.arrayContaining(['bg-cyan-300']));
        expect(deny.text()).toBe('Negar');
        expect(deny.classes()).toContain('bg-transparent');
        expect(deny.classes()).not.toContain('bg-cyan-300');

        wrapper.unmount();
    });

    it('pulses the amber border on entry, but not under reduced motion nor once decided', () => {
        const pending = mount(ApprovalCard, { props: { approval: makeApproval() } });
        expect(pending.get('[data-testid="approval-card"]').classes()).toEqual(expect.arrayContaining(['border-amber-400/70', 'approval-card--pulse']));
        pending.unmount();

        const decided = mount(ApprovalCard, { props: { approval: makeApproval({ status: 'approved', decided_at: '2026-11-02T12:05:00+00:00' }) } });
        expect(decided.get('[data-testid="approval-card"]').classes()).not.toContain('approval-card--pulse');
        decided.unmount();

        vi.spyOn(window, 'matchMedia').mockImplementation(
            (query) =>
                ({
                    matches: query.includes('prefers-reduced-motion'),
                    media: query,
                    addEventListener: vi.fn(),
                    removeEventListener: vi.fn(),
                }) as unknown as MediaQueryList,
        );

        const still = mount(ApprovalCard, { props: { approval: makeApproval() } });
        expect(still.get('[data-testid="approval-card"]').classes()).not.toContain('approval-card--pulse');
        still.unmount();
    });
});

afterEach(() => {
    vi.restoreAllMocks();
});
