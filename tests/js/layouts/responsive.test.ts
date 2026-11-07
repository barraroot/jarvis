import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import ApprovalCard from '@/components/hud/ApprovalCard.vue';
import MessageBubble from '@/components/hud/MessageBubble.vue';
import ResultTable from '@/components/results/ResultTable.vue';
import HudModal from '@/components/ui/HudModal.vue';
import { LAYOUT_MEDIA_QUERIES, useLayoutMode } from '@/composables/useLayoutMode';
import { SIDEBAR_COLLAPSED_STORAGE_KEY } from '@/layouts/constants';
import HudLayout from '@/layouts/HudLayout.vue';
import { useApprovalsStore } from '@/stores/approvals';
import { useTasksStore } from '@/stores/tasks';
import { useVisualizationsStore } from '@/stores/visualizations';
import type { ApprovalSummary, Message, TaskSummary, Visualization } from '@/types/models';
import { mockViewport } from '../helpers/matchMedia';

const Blank = { render: () => null };

/** Side panel behaviour is checked on `/settings`; the `hud` route renders the stage. */
async function mountLayout(path = '/settings') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'hud', component: Blank },
            { path: '/settings', name: 'settings', component: Blank },
            { path: '/memories', name: 'memories', component: Blank },
            { path: '/approvals', name: 'approvals', component: Blank },
            { path: '/activity', name: 'activity', component: Blank },
        ],
    });
    await router.push(path);

    return mount(HudLayout, { global: { plugins: [router] }, attachTo: document.body });
}

const TOUCH_TARGET = /(^|\s)(min-h-11|size-11|size-14|h-11)(\s|$)/;
/** Both axes ≥ 44 px (UI-23): `min-h-11`+`min-w-11`, `size-11`/`size-14` or the 84 px push-to-talk. */
const TOUCH_TARGET_BOTH_AXES = (classes: string): boolean =>
    /(^|\s)(size-11|size-14|size-\[84px\])(\s|$)/.test(classes) ||
    (/(^|\s)(min-h-11|h-11)(\s|$)/.test(classes) && /(^|\s)(min-w-11|w-full)(\s|$)/.test(classes));

function pendingApproval(id: string): ApprovalSummary {
    return {
        id,
        status: 'pending',
        tool: { identifier: 'whatsapp.send_message', name: 'Enviar mensagem', description: 'Envia.' },
        effect: { is_write: true, is_sensitive: false, external: true },
        params: {},
        agent: 'Communication',
        requested_at: '2026-11-07T14:00:00+00:00',
        expires_at: new Date(Date.now() + 3600_000).toISOString(),
        decided_at: null,
        decision_reason: null,
    };
}

describe('useLayoutMode (matchMedia)', () => {
    it.each([
        [360, 'mobile'],
        [768, 'tablet'],
        [1024, 'tablet'],
        [1280, 'desktop'],
        [1920, 'desktop'],
    ])('maps %ipx to %s', (width, expected) => {
        mockViewport(width);

        expect(useLayoutMode().mode.value).toBe(expected);
    });

    it('follows matchMedia change events', () => {
        const viewport = mockViewport(1280);
        const { mode } = useLayoutMode();

        viewport.resize(800);
        expect(mode.value).toBe('tablet');

        viewport.resize(360);
        expect(mode.value).toBe('mobile');
    });

    it('uses the Tailwind md/xl breakpoints', () => {
        expect(LAYOUT_MEDIA_QUERIES.tablet).toContain('768px');
        expect(LAYOUT_MEDIA_QUERIES.desktop).toContain('1280px');
    });
});

describe('HudLayout — mobile (360px)', () => {
    it('makes the conversation the main area with the compact core on top and the five-item bottom nav', async () => {
        mockViewport(360);
        const wrapper = await mountLayout();

        expect(wrapper.get('[data-testid="hud-layout"]').attributes('data-layout')).toBe('mobile');
        expect(wrapper.get('[data-testid="hud-layout"]').classes()).toEqual(expect.arrayContaining(['overflow-x-hidden', 'w-full']));
        expect(wrapper.get('[data-testid="layout-sidebar"]').classes()).toContain('hidden');

        expect(wrapper.find('[data-testid="mobile-core"] [data-testid="jarvis-core"]').exists()).toBe(true);

        const nav = wrapper.get('[data-testid="bottom-nav"]');
        const navItems = Array.from(nav.element.children).map(
            (item) => item.getAttribute('data-testid') ?? item.querySelector('[data-testid]')?.getAttribute('data-testid'),
        );
        expect(navItems).toEqual([
            'bottom-nav-hud',
            'bottom-nav-conversations',
            'bottom-nav-activity',
            'bottom-nav-approvals',
            'bottom-nav-more',
        ]);
        expect(Array.from(nav.element.children).map((item) => item.textContent?.trim())).toEqual([
            'HUD',
            'Conversas',
            'Atividade',
            'Aprovações',
            'Mais',
        ]);

        // The old central microphone is gone: push-to-talk lives in the strip above the nav.
        expect(nav.find('[data-testid="push-to-talk"]').exists()).toBe(false);
        const strip = wrapper.get('[data-testid="mobile-controls"] [data-testid="bottom-nav-microphone"]');
        expect(strip.findAll('button').map((button) => button.attributes('data-testid'))).toEqual([
            'bottom-nav-compose',
            'push-to-talk',
            'bottom-nav-sleep',
        ]);
        expect(strip.get('[data-testid="push-to-talk"]').classes()).toEqual(expect.arrayContaining(['size-[84px]', 'border-amber-400']));
        expect(strip.get('[data-testid="push-to-talk"]').attributes('aria-label')).toBe('Segure para falar');
        expect(strip.get('[data-testid="bottom-nav-compose"]').text()).toBe('Digitar');
        expect(strip.get('[data-testid="bottom-nav-sleep"]').text()).toBe('Dormir');

        wrapper.unmount();
    });

    it('shows the approvals badge with one pending approval and hides it at zero', async () => {
        mockViewport(360);
        const wrapper = await mountLayout();
        const approvals = useApprovalsStore();

        expect(wrapper.find('[data-testid="bottom-nav-approvals-badge"]').exists()).toBe(false);

        approvals.items = [pendingApproval('1')];
        await nextTick();
        expect(wrapper.get('[data-testid="bottom-nav-approvals-badge"]').text()).toBe('1');

        approvals.items = [{ ...pendingApproval('1'), status: 'approved' }];
        await nextTick();
        expect(wrapper.find('[data-testid="bottom-nav-approvals-badge"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('puts icon-only Agentes (with badge) and Mutar microfone in the mobile header', async () => {
        mockViewport(360);
        const wrapper = await mountLayout('/');
        const actions = wrapper.get('[data-testid="header-actions-mobile"]');

        expect(actions.get('[data-testid="header-agents-mobile"]').attributes('aria-label')).toBe('Agentes');
        expect(actions.get('[data-testid="header-agents-mobile"]').text()).toBe('');
        expect(actions.get('[data-testid="header-mic-mobile"]').attributes('aria-label')).toBe('Mutar microfone');
        expect(actions.get('[data-testid="header-mic-mobile"]').text()).toBe('');
        expect(actions.find('[data-testid="header-agents-mobile-badge"]').exists()).toBe(false);

        useTasksStore().tasks = [{ id: 1, status: 'running' }] as unknown as TaskSummary[];
        await nextTick();
        expect(actions.get('[data-testid="header-agents-mobile-badge"]').text()).toBe('1');

        wrapper.unmount();
    });

    it('opens AGENTES as an expandable HudSheet from the header icon', async () => {
        mockViewport(360);
        const wrapper = await mountLayout();
        const sheet = () => wrapper.get('[data-testid="hud-sheet"]');

        expect(wrapper.find('[data-testid="hud-sheet"]').exists()).toBe(false);

        await wrapper.get('[data-testid="header-agents-mobile"]').trigger('click');

        expect(sheet().attributes('role')).toBe('dialog');
        expect(sheet().attributes('aria-label')).toBe('Agentes');
        expect(sheet().attributes('data-expanded')).toBe('false');
        expect(sheet().classes()).toEqual(expect.arrayContaining(['bottom-0', 'max-h-[60dvh]']));
        expect(sheet().classes()).not.toContain('rounded-t-md');
        expect(sheet().find('[data-testid="layout-sheet"]').exists()).toBe(true);
        expect(sheet().text()).not.toContain('Núcleo');
        expect(sheet().findAll('h2').map((title) => title.text())).toEqual(['Agentes ativos']);
        expect(wrapper.get('[data-testid="layout-sidebar"]').classes()).toContain('hidden');

        await wrapper.get('[data-testid="sheet-handle"]').trigger('click');
        expect(sheet().attributes('data-expanded')).toBe('true');
        expect(sheet().classes()).toContain('top-14');

        // Tapping the same icon again closes the sheet.
        await wrapper.get('[data-testid="header-agents-mobile"]').trigger('click');
        expect(wrapper.find('[data-testid="hud-sheet"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('shows VISUALIZAÇÃO and the pending approval as HudSheets on the mobile HUD', async () => {
        mockViewport(360);
        const wrapper = await mountLayout('/');

        useVisualizationsStore().apply(makeVisualization());
        await nextTick();

        expect(wrapper.find('[data-testid="hud-stage-visualization"]').exists()).toBe(false);
        const visualizationSheet = wrapper.get('[data-testid="hud-stage-visualization-sheet"]');
        expect(visualizationSheet.element.closest('[data-testid="hud-sheet"]')?.getAttribute('aria-label')).toBe('Painel de visualização');
        expect(visualizationSheet.find('[data-testid="viz-title"]').text()).toBe('Pesquisa');
        expect(wrapper.get('[data-testid="hud-stage-content"]').attributes('style') ?? '').not.toContain('display: none');

        useApprovalsStore().items = [pendingApproval('9')];
        await nextTick();

        const approvalSheet = wrapper.get('[data-testid="hud-stage-approval-sheet"]');
        expect(approvalSheet.element.closest('[data-testid="hud-sheet"]')?.getAttribute('aria-label')).toBe('Aprovação necessária');
        expect(approvalSheet.findAll('[data-testid="approval-approve"]')).toHaveLength(1);

        wrapper.unmount();
    });

    it('gives every button and link of the bottom nav and the mobile HUD a 44px touch area', async () => {
        mockViewport(360);
        const wrapper = await mountLayout('/');

        useApprovalsStore().items = [pendingApproval('1')];
        await nextTick();
        await wrapper.get('[data-testid="bottom-nav-more"]').trigger('click');

        const scopes = [
            '[data-testid="mobile-controls"]',
            '[data-testid="header-actions-mobile"]',
            '[data-testid="hud-stage"]',
        ];
        const controls = scopes.flatMap((scope) => wrapper.get(scope).findAll('button, a'));

        expect(controls.length).toBeGreaterThanOrEqual(12);
        expect(wrapper.find('[data-testid="bottom-nav-memories"]').exists()).toBe(true);

        for (const control of controls) {
            const label = control.attributes('data-testid') ?? control.text();
            expect(TOUCH_TARGET_BOTH_AXES(control.attributes('class') ?? ''), label).toBe(true);
        }

        for (const selector of [
            '[data-testid="drawer-toggle"]',
            '[data-testid="header-notifications"]',
            '[data-testid="footer-send"]',
            '[data-testid="footer-mute"]',
        ]) {
            expect(wrapper.get(selector).attributes('class'), selector).toMatch(TOUCH_TARGET);
        }

        wrapper.unmount();
    });

    it('switches to the tablet layout and closes the sheet on rotation', async () => {
        const viewport = mockViewport(360);
        const wrapper = await mountLayout();

        await wrapper.get('[data-testid="header-agents-mobile"]').trigger('click');
        expect(wrapper.find('[data-testid="hud-sheet"]').exists()).toBe(true);

        viewport.resize(1024);
        await nextTick();

        expect(wrapper.get('[data-testid="hud-layout"]').attributes('data-layout')).toBe('tablet');
        expect(wrapper.find('[data-testid="mobile-core"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="hud-sheet"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="layout-sidebar"]').classes()).not.toContain('flex');

        wrapper.unmount();
    });
});

describe('HudLayout — tablet (768px / 1024px)', () => {
    it.each([768, 1024])('starts with the side panel collapsed at %ipx and reopens it on toggle', async (width) => {
        mockViewport(width);
        const wrapper = await mountLayout();
        const sidebar = () => wrapper.get('[data-testid="layout-sidebar"]');

        expect(wrapper.get('[data-testid="hud-layout"]').attributes('data-layout')).toBe('tablet');
        expect(sidebar().classes()).toEqual(expect.arrayContaining(['md:hidden', 'xl:flex']));
        expect(wrapper.get('[data-testid="sidebar-toggle"]').attributes('aria-expanded')).toBe('false');
        expect(wrapper.get('[data-testid="sidebar-toggle"]').classes()).toContain('size-11');
        expect(wrapper.find('[data-testid="mobile-core"]').exists()).toBe(false);

        await wrapper.get('[data-testid="sidebar-toggle"]').trigger('click');

        expect(sidebar().classes()).toContain('md:flex');
        expect(localStorage.getItem(SIDEBAR_COLLAPSED_STORAGE_KEY)).toBe('false');

        wrapper.unmount();
    });

    it('respects a stored preference over the tablet default', async () => {
        localStorage.setItem(SIDEBAR_COLLAPSED_STORAGE_KEY, 'false');
        mockViewport(1024);
        const wrapper = await mountLayout();

        expect(wrapper.get('[data-testid="layout-sidebar"]').classes()).toContain('md:flex');

        wrapper.unmount();
    });
});

describe('HudLayout — desktop (1280px / 1920px)', () => {
    it.each([1280, 1920])('keeps the side panel open at %ipx with no mobile chrome', async (width) => {
        mockViewport(width);
        const wrapper = await mountLayout();

        expect(wrapper.get('[data-testid="hud-layout"]').attributes('data-layout')).toBe('desktop');
        expect(wrapper.get('[data-testid="layout-sidebar"]').classes()).toEqual(expect.arrayContaining(['md:flex', 'md:w-80']));
        expect(wrapper.get('[data-testid="layout-footer"]').classes()).toContain('md:pl-80');
        expect(wrapper.get('[data-testid="bottom-nav"]').classes()).toContain('md:hidden');
        expect(wrapper.find('[data-testid="mobile-core"]').exists()).toBe(false);

        wrapper.unmount();
    });
});

function makeVisualization(): Visualization {
    return {
        id: 4,
        task_id: 8,
        title: 'Pesquisa',
        source: null,
        status: 'updated',
        created_at: '2026-11-04T10:00:00Z',
        updated_at: '2026-11-04T10:00:00Z',
        closed_at: null,
        sections: [{ key: 'question', position: 1, label: 'PERGUNTA', type: 'question', headline: 'Pergunta' }],
    };
}

/** Test ids of the stage children, in DOM order. */
function stageOrder(wrapper: Awaited<ReturnType<typeof mountLayout>>): string[] {
    return wrapper
        .get('[data-testid="hud-stage"]')
        .findAll('[data-testid^="hud-stage-"]')
        .map((child) => child.attributes('data-testid') ?? '');
}

describe('HUD stage stacked on tablet/mobile (UI-09)', () => {
    it.each([768, 1024])('stacks compact core, AGENTES and VISUALIZAÇÃO at %ipx', async (width) => {
        mockViewport(width);
        const wrapper = await mountLayout('/');
        const stage = () => wrapper.get('[data-testid="hud-stage"]');

        expect(stage().attributes('data-stage')).toBe('stacked');
        expect(stage().classes()).toEqual(expect.arrayContaining(['flex', 'flex-col']));
        expect(wrapper.find('[data-testid="mobile-core"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="hud-stage-core"] [data-testid="jarvis-core"]').classes()).toContain('core--compact');
        expect(stageOrder(wrapper)).toEqual(['hud-stage-core', 'hud-stage-agents', 'hud-stage-content']);

        useVisualizationsStore().apply(makeVisualization());
        await nextTick();

        expect(stage().attributes('data-stage')).toBe('stacked');
        expect(stageOrder(wrapper)).toEqual(['hud-stage-core', 'hud-stage-agents', 'hud-stage-visualization', 'hud-stage-content']);
        expect(wrapper.get('[data-testid="hud-stage-visualization"]').classes()).toContain('w-full');
        expect(wrapper.find('[data-testid="hud-sheet"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('keeps the compact core on top at 360px with VISUALIZAÇÃO in a sheet', async () => {
        mockViewport(360);
        const wrapper = await mountLayout('/');
        const stage = () => wrapper.get('[data-testid="hud-stage"]');

        expect(stage().attributes('data-stage')).toBe('stacked');
        expect(wrapper.find('[data-testid="mobile-core"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="hud-stage-core"] [data-testid="jarvis-core"]').classes()).toContain('core--compact');
        expect(stageOrder(wrapper)).toEqual(['hud-stage-core', 'hud-stage-content']);

        useVisualizationsStore().apply(makeVisualization());
        await nextTick();

        expect(stageOrder(wrapper)).toEqual(['hud-stage-core', 'hud-stage-content', 'hud-stage-visualization-sheet']);

        wrapper.unmount();
    });

    it('hides the side panel on tablet but keeps the mobile bottom sheet on the HUD', async () => {
        const viewport = mockViewport(1024);
        const wrapper = await mountLayout('/');

        expect(wrapper.find('[data-testid="layout-sidebar"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="sidebar-toggle"]').exists()).toBe(false);

        viewport.resize(360);
        await nextTick();

        await wrapper.get('[data-testid="header-agents-mobile"]').trigger('click');
        expect(wrapper.get('[data-testid="hud-sheet"] [data-testid="layout-sheet"]').attributes('data-section')).toBe('agents');

        wrapper.unmount();
    });

    it('switches from the stacked stage to the centered core when growing to desktop', async () => {
        const viewport = mockViewport(1024);
        const wrapper = await mountLayout('/');

        viewport.resize(1280);
        await nextTick();

        expect(wrapper.get('[data-testid="hud-stage"]').attributes('data-stage')).toBe('center');

        wrapper.unmount();
    });
});

describe('cards usable at 360px', () => {
    const approval: ApprovalSummary = {
        id: '7',
        status: 'pending',
        tool: { identifier: 'erp.send_payment_reminder', name: 'Enviar lembrete de pagamento para todos os clientes', description: 'Envia lembrete.' },
        effect: { is_write: true, is_sensitive: true, external: true },
        params: { customer_ids: [42, 77] },
        agent: 'Automation',
        requested_at: '2026-11-02T12:00:00+00:00',
        expires_at: new Date(Date.now() + 3600_000).toISOString(),
        decided_at: null,
        decision_reason: null,
    };

    it('ApprovalCard wraps its actions instead of overflowing', () => {
        mockViewport(360);
        const wrapper = mount(ApprovalCard, { props: { approval } });

        expect(wrapper.get('[data-testid="approval-approve"]').element.parentElement?.className).toContain('flex-wrap');
        expect(wrapper.get('[data-testid="approval-approve"]').classes()).toContain('min-h-11');
        expect(wrapper.get('[data-testid="approval-title"]').classes()).toEqual(expect.arrayContaining(['min-w-0', 'truncate']));
    });

    it('ResultTable scrolls inside its panel and keeps sortable headers tappable', () => {
        mockViewport(360);
        const wrapper = mount(ResultTable, {
            props: {
                payload: {
                    type: 'table',
                    caption: 'Tabela',
                    columns: ['A', 'B', 'C', 'D', 'E', 'F'],
                    rows: [['1', '2', '3', '4', '5', '6']],
                },
            },
        });

        expect(wrapper.get('table').element.parentElement?.className).toEqual(expect.stringContaining('overflow-x-auto'));
        expect(wrapper.get('[data-testid="result-table-sort-0"]').classes()).toContain('min-h-11');
    });

    it('assistant bubbles take the full width on mobile so cards fit', () => {
        mockViewport(360);
        const message = {
            id: 'm1',
            conversation_id: 'c1',
            role: 'assistant',
            content: 'ok',
            status: 'completed',
            source: null,
            payload: null,
            created_at: new Date().toISOString(),
        } as unknown as Message;
        const wrapper = mount(MessageBubble, { props: { message } });
        const bubble = wrapper.get('[data-testid="message-bubble-assistant"]').element.firstElementChild as HTMLElement;

        expect(bubble.className).toContain('max-w-full');
        expect(bubble.className).toContain('min-w-0');
    });

    it('HudModal (TaskDetail) fits 360px with a tappable close button', () => {
        mockViewport(360);
        const wrapper = mount(HudModal, { props: { open: true, title: 'Tarefa' }, attachTo: document.body });
        const dialog = document.querySelector('[data-testid="hud-modal"] [role="dialog"]') ?? document.querySelector('[data-testid="hud-modal"] > div:nth-child(2)');

        expect(dialog?.className).toEqual(expect.stringContaining('w-full'));
        expect(dialog?.className).toEqual(expect.stringContaining('max-h-[90dvh]'));
        expect(document.querySelector('[data-testid="hud-modal-close"]')?.className).toContain('size-11');

        wrapper.unmount();
    });
});
