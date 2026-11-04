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
import { useVisualizationsStore } from '@/stores/visualizations';
import type { ApprovalSummary, Message, Visualization } from '@/types/models';
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
        ],
    });
    await router.push(path);

    return mount(HudLayout, { global: { plugins: [router] }, attachTo: document.body });
}

const TOUCH_TARGET = /(^|\s)(min-h-11|size-11|size-14|h-11)(\s|$)/;

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
    it('makes the conversation the main area with the compact core on top and the bottom nav', async () => {
        mockViewport(360);
        const wrapper = await mountLayout();

        expect(wrapper.get('[data-testid="hud-layout"]').attributes('data-layout')).toBe('mobile');
        expect(wrapper.get('[data-testid="hud-layout"]').classes()).toEqual(expect.arrayContaining(['overflow-x-hidden', 'w-full']));
        expect(wrapper.get('[data-testid="layout-sidebar"]').classes()).toContain('hidden');

        expect(wrapper.find('[data-testid="mobile-core"] [data-testid="jarvis-core"]').exists()).toBe(true);

        const navItems = wrapper.get('[data-testid="bottom-nav"]').element.children;
        expect(navItems).toHaveLength(5);
        // Microphone is the central item.
        expect(navItems[2]?.getAttribute('data-testid')).toBe('bottom-nav-microphone');

        wrapper.unmount();
    });

    it('opens agents/tasks as an expandable bottom sheet without the core', async () => {
        mockViewport(360);
        const wrapper = await mountLayout();
        const sidebar = () => wrapper.get('[data-testid="layout-sidebar"]');

        await wrapper.get('[data-testid="bottom-nav-agents"]').trigger('click');

        expect(sidebar().attributes('data-sheet')).toBe('half');
        expect(sidebar().classes()).toEqual(expect.arrayContaining(['flex', 'bottom-0', 'w-full', 'max-h-[60dvh]']));
        expect(sidebar().text()).toContain('Agentes ativos');
        expect(sidebar().text()).not.toContain('Núcleo');
        expect(sidebar().findAll('h2').map((title) => title.text())).toEqual(['Agentes ativos']);

        await wrapper.get('[data-testid="sheet-handle"]').trigger('click');
        expect(sidebar().attributes('data-sheet')).toBe('expanded');
        expect(sidebar().classes()).toContain('top-14');

        // Tapping the same section again closes the sheet.
        await wrapper.get('[data-testid="bottom-nav-agents"]').trigger('click');
        expect(sidebar().classes()).toContain('hidden');

        await wrapper.get('[data-testid="bottom-nav-tasks"]').trigger('click');
        expect(sidebar().attributes('data-sheet')).toBe('half');
        expect(sidebar().findAll('h2').map((title) => title.text())).toContain('Tarefas');

        wrapper.unmount();
    });

    it('gives every navigation control a touch area of at least 44px', async () => {
        mockViewport(360);
        const wrapper = await mountLayout();

        const controls = [
            '[data-testid="drawer-toggle"]',
            '[data-testid="bottom-nav-chat"]',
            '[data-testid="bottom-nav-agents"]',
            '[data-testid="bottom-nav-microphone"]',
            '[data-testid="bottom-nav-tasks"]',
            '[data-testid="bottom-nav-settings"]',
            '[data-testid="header-mic-mobile"]',
            '[data-testid="header-more"]',
            '[data-testid="header-notifications"]',
            '[data-testid="footer-send"]',
            '[data-testid="footer-mute"]',
        ];

        for (const selector of controls) {
            expect(wrapper.get(selector).attributes('class'), selector).toMatch(TOUCH_TARGET);
        }

        wrapper.unmount();
    });

    it('switches to the tablet layout and closes the sheet on rotation', async () => {
        const viewport = mockViewport(360);
        const wrapper = await mountLayout();

        await wrapper.get('[data-testid="bottom-nav-tasks"]').trigger('click');
        viewport.resize(1024);
        await nextTick();

        expect(wrapper.get('[data-testid="hud-layout"]').attributes('data-layout')).toBe('tablet');
        expect(wrapper.find('[data-testid="mobile-core"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="layout-sidebar"]').attributes('data-sheet')).toBeUndefined();

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
    it.each([360, 768, 1024])('stacks compact core, AGENTES and VISUALIZAÇÃO at %ipx', async (width) => {
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

        wrapper.unmount();
    });

    it('hides the side panel on tablet but keeps the mobile bottom sheet on the HUD', async () => {
        const viewport = mockViewport(1024);
        const wrapper = await mountLayout('/');

        expect(wrapper.find('[data-testid="layout-sidebar"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="sidebar-toggle"]').exists()).toBe(false);

        viewport.resize(360);
        await nextTick();

        await wrapper.get('[data-testid="bottom-nav-tasks"]').trigger('click');
        expect(wrapper.get('[data-testid="layout-sidebar"]').attributes('data-sheet')).toBe('half');

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
