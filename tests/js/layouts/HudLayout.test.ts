import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { useVisualizationsStore } from '@/stores/visualizations';
import type { Visualization } from '@/types/models';
import { SIDEBAR_COLLAPSED_STORAGE_KEY } from '@/layouts/constants';
import HudLayout from '@/layouts/HudLayout.vue';
import { mockFetch } from '../helpers/fetch';
import { mockViewport } from '../helpers/matchMedia';

const Blank = { render: () => null };

/** Side panel behaviour is checked on `/settings`; the `hud` route renders the stage. */
async function mountLayout(slots: Record<string, string> = {}, path = '/settings', options: { animate?: boolean } = {}) {
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

    return mount(HudLayout, {
        slots,
        global: { plugins: [router], stubs: options.animate ? { transition: false } : {} },
    });
}

describe('HudLayout', () => {
    it('renders the named slots header, sidebar, main and footer', async () => {
        const wrapper = await mountLayout({
            header: '<span id="h">h</span>',
            sidebar: '<span id="s">s</span>',
            main: '<span id="m">m</span>',
            footer: '<span id="f">f</span>',
        });

        expect(wrapper.find('[data-testid="layout-header"] #h').exists()).toBe(true);
        expect(wrapper.find('[data-testid="layout-sidebar"] #s').exists()).toBe(true);
        expect(wrapper.find('[data-testid="layout-main"] #m').exists()).toBe(true);
        expect(wrapper.find('[data-testid="layout-footer"] #f').exists()).toBe(true);
    });

    it('mounts the HUD chrome in the default slots, empty states only', async () => {
        const wrapper = await mountLayout();

        expect(wrapper.find('[data-testid="app-header"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="layout-main"]').text()).toBe('sem dados');
        expect(wrapper.find('[data-testid="interaction-footer"]').exists()).toBe(true);

        const sidebar = wrapper.get('[data-testid="layout-sidebar"]');
        expect(sidebar.text()).toContain('Núcleo');
        expect(sidebar.findAll('[data-testid="empty-state"]').length).toBeGreaterThanOrEqual(4);
    });

    it('uses a 320px desktop sidebar and offsets the content', async () => {
        mockViewport(1280);
        const wrapper = await mountLayout();
        const sidebar = wrapper.get('[data-testid="layout-sidebar"]');

        expect(sidebar.classes()).toContain('md:w-80');
        expect(sidebar.classes()).toContain('md:flex');
        expect(wrapper.get('[data-testid="layout-footer"]').classes()).toContain('md:pl-80');
    });

    it('persists the tablet collapse state in localStorage', async () => {
        mockViewport(1280);
        const wrapper = await mountLayout();

        await wrapper.get('[data-testid="sidebar-toggle"]').trigger('click');

        expect(localStorage.getItem(SIDEBAR_COLLAPSED_STORAGE_KEY)).toBe('true');
        const sidebar = wrapper.get('[data-testid="layout-sidebar"]');
        expect(sidebar.classes()).toEqual(expect.arrayContaining(['md:hidden', 'xl:flex']));

        wrapper.unmount();
        const remounted = await mountLayout();

        expect(remounted.get('[data-testid="layout-sidebar"]').classes()).toContain('md:hidden');
        expect(remounted.get('[data-testid="sidebar-toggle"]').attributes('aria-expanded')).toBe('false');
    });

    it('opens the mobile drawer from the menu button and closes it on backdrop', async () => {
        const wrapper = await mountLayout();
        const sidebar = () => wrapper.get('[data-testid="layout-sidebar"]');

        expect(sidebar().classes()).toContain('hidden');

        await wrapper.get('[data-testid="drawer-toggle"]').trigger('click');
        expect(sidebar().classes()).toContain('flex');

        await wrapper.get('[data-testid="drawer-backdrop"]').trigger('click');
        expect(sidebar().classes()).toContain('hidden');
    });

    it('shows the mobile bottom navigation with a highlighted microphone', async () => {
        const wrapper = await mountLayout();
        const nav = wrapper.get('[data-testid="bottom-nav"]');

        expect(nav.classes()).toContain('md:hidden');
        expect(nav.text()).toContain('Chat');
        expect(nav.text()).toContain('Agentes');
        expect(nav.text()).toContain('Tarefas');
        expect(nav.text()).toContain('Config');

        await wrapper.get('[data-testid="bottom-nav-microphone"]').trigger('click');
        expect(wrapper.emitted('microphone')).toHaveLength(1);

        await wrapper.get('[data-testid="bottom-nav-agents"]').trigger('click');
        expect(wrapper.get('[data-testid="layout-sidebar"]').classes()).toContain('flex');
    });

    it('opens the drawer as an expandable sheet for the selected bottom nav section', async () => {
        const wrapper = await mountLayout();
        const sectionTitles = () => wrapper.get('[data-testid="layout-sidebar"]').findAll('h2').map((title) => title.text());

        await wrapper.get('[data-testid="bottom-nav-agents"]').trigger('click');
        expect(sectionTitles()).toContain('Agentes ativos');
        expect(sectionTitles()).not.toContain('Tarefas');

        await wrapper.get('[data-testid="bottom-nav-tasks"]').trigger('click');
        expect(sectionTitles()).toContain('Tarefas');
        expect(sectionTitles()).not.toContain('Agentes ativos');
    });

    it('prevents horizontal scroll on narrow screens', async () => {
        const wrapper = await mountLayout();

        expect(wrapper.get('[data-testid="hud-layout"]').classes()).toEqual(
            expect.arrayContaining(['overflow-x-hidden', 'w-full']),
        );
    });
});

describe('HudLayout connection banner', () => {
    it('shows a banner and disables sending while disconnected', async () => {
        const { useConnectionStore } = await import('@/stores/connection');
        const wrapper = await mountLayout({ footer: '<input id="composer" />' });
        const connection = useConnectionStore();

        expect(wrapper.find('[data-testid="disconnected-banner"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="layout-footer-controls"]').attributes('disabled')).toBeUndefined();

        connection.state = 'disconnected';
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[data-testid="disconnected-banner"]').text()).toContain('Sem conexão');
        expect(wrapper.get('[data-testid="layout-footer-controls"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="bottom-nav-microphone"]').attributes('disabled')).toBeDefined();

        connection.state = 'connected';
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="disconnected-banner"]').exists()).toBe(false);
    });
});

function makeVisualization(overrides: Partial<Visualization> = {}): Visualization {
    return {
        id: 3,
        task_id: 5,
        title: 'Busca no blog',
        source: 'Beer And Code',
        status: 'syncing',
        created_at: '2026-11-04T10:00:00Z',
        updated_at: '2026-11-04T10:00:00Z',
        closed_at: null,
        sections: [{ key: 'question', position: 1, label: 'PERGUNTA', type: 'question', headline: 'Aula de inglês' }],
        ...overrides,
    };
}

describe('HudLayout — HUD stage (UI-09)', () => {
    it('centers the core without a side panel when no visualization is open', async () => {
        mockViewport(1280);
        const wrapper = await mountLayout({ main: '<span id="page">página</span>' }, '/');
        const stage = wrapper.get('[data-testid="hud-stage"]');

        expect(stage.attributes('data-stage')).toBe('center');
        expect(wrapper.find('[data-testid="layout-sidebar"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="sidebar-toggle"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="layout-footer"]').classes()).not.toContain('md:pl-80');
        expect(stage.find('[data-testid="hud-stage-core"] [data-testid="jarvis-core"]').exists()).toBe(true);
        expect(stage.find('[data-testid="hud-stage-core"] .core--full').exists()).toBe(true);
        expect(stage.find('[data-testid="hud-stage-visualization"]').exists()).toBe(false);
        expect(stage.find('[data-testid="hud-stage-agents"]').exists()).toBe(false);
        expect(stage.find('[data-testid="hud-stage-content"] #page').exists()).toBe(true);
    });

    it('moves the core to the corner with AGENTES below and VISUALIZAÇÃO to the right', async () => {
        mockViewport(1280);
        const wrapper = await mountLayout({}, '/');

        useVisualizationsStore().apply(makeVisualization());
        await nextTick();

        const stage = wrapper.get('[data-testid="hud-stage"]');
        expect(stage.attributes('data-stage')).toBe('corner');
        expect(stage.get('[data-testid="hud-stage-core"]').classes()).toEqual(
            expect.arrayContaining(['col-start-1', 'row-start-1']),
        );
        expect(stage.get('[data-testid="hud-stage-agents"]').classes()).toEqual(
            expect.arrayContaining(['col-start-1', 'row-start-2']),
        );
        expect(stage.find('[data-testid="hud-stage-agents"] [data-testid="agents-panel"]').exists()).toBe(true);
        expect(stage.get('[data-testid="hud-stage-visualization"]').classes()).toEqual(
            expect.arrayContaining(['col-start-2', 'row-span-2']),
        );
        expect(stage.get('[data-testid="viz-title"]').text()).toBe('Busca no blog');
        expect(stage.get('[data-testid="hud-stage-content"]').attributes('style')).toContain('display: none');
    });

    it('closing the visualization brings the core back to the center', async () => {
        mockViewport(1280);
        const { calls } = mockFetch({
            'POST /api/v1/visualizations/3/close': { body: { data: makeVisualization({ closed_at: '2026-11-04T10:05:00Z' }) } },
        });
        const wrapper = await mountLayout({}, '/');

        useVisualizationsStore().apply(makeVisualization());
        await nextTick();
        await wrapper.get('[data-testid="viz-close"]').trigger('click');

        const stage = wrapper.get('[data-testid="hud-stage"]');
        expect(stage.attributes('data-stage')).toBe('center');
        expect(stage.find('[data-testid="hud-stage-visualization"]').exists()).toBe(false);
        expect(stage.find('[data-testid="hud-stage-agents"]').exists()).toBe(false);
        expect(stage.find('[data-testid="hud-stage-core"] .core--full').exists()).toBe(true);
        expect(calls.some((call) => call.method === 'POST' && call.url === '/api/v1/visualizations/3/close')).toBe(true);
    });

    it('animates the panels in with the 700 ms signature transition (UI-06)', async () => {
        mockViewport(1280);
        const wrapper = await mountLayout({}, '/', { animate: true });

        useVisualizationsStore().apply(makeVisualization());
        await nextTick();

        expect(wrapper.get('[data-testid="hud-stage"]').attributes('data-motion')).toBe('full');
        expect(wrapper.get('[data-testid="hud-stage-core"]').classes()).toContain('will-change-transform');
        expect(wrapper.get('[data-testid="hud-stage-visualization"]').classes()).toEqual(
            expect.arrayContaining(['transition-all', 'duration-[700ms]']),
        );
    });

    it('applies the final position without transition classes under reduced motion (RNF-06)', async () => {
        mockViewport(1280, { reducedMotion: true });
        const wrapper = await mountLayout({}, '/', { animate: true });

        useVisualizationsStore().apply(makeVisualization());
        await nextTick();

        expect(wrapper.get('[data-testid="hud-stage"]').attributes('data-motion')).toBe('reduced');
        expect(wrapper.get('[data-testid="hud-stage"]').attributes('data-stage')).toBe('corner');
        expect(wrapper.get('[data-testid="hud-stage-core"]').classes()).not.toContain('will-change-transform');

        for (const selector of ['[data-testid="hud-stage-visualization"]', '[data-testid="hud-stage-agents"]']) {
            const classes = wrapper.get(selector).classes();
            expect(classes, selector).not.toContain('transition-all');
            expect(classes, selector).not.toContain('opacity-0');
        }
    });

    it('opens AGENTES as a drawer from the header (Alt+1) and closes it when a visualization opens', async () => {
        mockViewport(1280);
        const wrapper = await mountLayout({}, '/');

        document.dispatchEvent(new KeyboardEvent('keydown', { code: 'Digit1', key: '1', altKey: true }));
        await nextTick();

        const drawer = wrapper.get('[data-testid="hud-stage-agents-drawer"]');
        expect(drawer.find('[data-testid="agents-panel"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="layout-sidebar"]').exists()).toBe(false);

        await wrapper.get('[data-testid="hud-stage-agents-drawer-close"]').trigger('click');
        expect(wrapper.find('[data-testid="hud-stage-agents-drawer"]').exists()).toBe(false);

        await wrapper.get('[data-testid="header-agents"]').trigger('click');
        expect(wrapper.find('[data-testid="hud-stage-agents-drawer"]').exists()).toBe(true);

        useVisualizationsStore().apply(makeVisualization());
        await nextTick();
        await nextTick();

        expect(wrapper.find('[data-testid="hud-stage-agents-drawer"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="hud-stage-agents"] [data-testid="agents-panel"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('keeps the side panel layout on every other route', async () => {
        mockViewport(1280);
        const wrapper = await mountLayout({}, '/settings');

        expect(wrapper.find('[data-testid="hud-stage"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="layout-sidebar"]').classes()).toContain('md:flex');
        expect(wrapper.get('[data-testid="layout-footer"]').classes()).toContain('md:pl-80');
    });

    it('shows the captions right above the text input', async () => {
        mockViewport(1280);
        const { useVoiceStore } = await import('@/stores/voice');
        const wrapper = await mountLayout({}, '/');

        useVoiceStore().userCaption = 'qual a melhor aula';
        await nextTick();

        const footer = wrapper.get('[data-testid="layout-footer"]').element;
        const captions = footer.querySelector('[data-testid="captions-bar"]');
        const controls = footer.querySelector('[data-testid="layout-footer-controls"]');

        expect(captions?.textContent).toContain('qual a melhor aula');
        expect(captions?.nextElementSibling).toBe(controls);
    });
});
