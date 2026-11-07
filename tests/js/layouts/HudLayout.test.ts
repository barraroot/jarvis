import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useVisualizationsStore } from '@/stores/visualizations';
import { useVoiceStore } from '@/stores/voice';
import type { Visualization } from '@/types/models';
import { SIDEBAR_COLLAPSED_STORAGE_KEY } from '@/layouts/constants';
import HudLayout from '@/layouts/HudLayout.vue';
import { makeUser, mockFetch } from '../helpers/fetch';
import { mockViewport } from '../helpers/matchMedia';

const Blank = { render: () => null };

/** Side panel behaviour is checked on `/settings`; the `hud` route renders the stage. */
async function mountLayout(slots: Record<string, string> = {}, path = '/settings', options: { animate?: boolean; attach?: boolean } = {}) {
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

    return mount(HudLayout, {
        slots,
        global: { plugins: [router], stubs: options.animate ? { transition: false } : {} },
        attachTo: options.attach ? document.body : undefined,
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

    it('shows the mobile bottom navigation with push-to-talk and the five items', async () => {
        mockViewport(360);
        const wrapper = await mountLayout({}, '/settings', { attach: true });
        const nav = wrapper.get('[data-testid="bottom-nav"]');

        expect(nav.classes()).toContain('md:hidden');
        expect(nav.element.children).toHaveLength(5);
        for (const label of ['HUD', 'Conversas', 'Atividade', 'Aprovações', 'Mais']) {
            expect(nav.text()).toContain(label);
        }
        expect(nav.text()).not.toContain('Microfone');

        // Holding push-to-talk while sleeping wakes the voice; releasing mutes without sleeping (RF-06).
        const ptt = wrapper.get('[data-testid="push-to-talk"]');
        await ptt.trigger('pointerdown', { button: 0, pointerId: 1 });
        expect(wrapper.emitted('wake')).toHaveLength(1);
        await ptt.trigger('pointerup', { button: 0, pointerId: 1 });
        expect(wrapper.emitted('microphone')).toHaveLength(1);
        expect(wrapper.emitted('sleep')).toBeUndefined();

        // `Dormir` sleeps once the window is open; `Digitar` focuses the composer.
        expect(wrapper.get('[data-testid="bottom-nav-sleep"]').attributes('disabled')).toBeDefined();
        useVoiceStore().phase = 'listening';
        await nextTick();
        await wrapper.get('[data-testid="bottom-nav-sleep"]').trigger('click');
        expect(wrapper.emitted('sleep')).toHaveLength(1);

        await wrapper.get('[data-testid="bottom-nav-compose"]').trigger('click');
        expect(document.activeElement).toBe(wrapper.get('[data-testid="composer"] textarea').element);

        // `Conversas` opens the conversations drawer.
        await wrapper.get('[data-testid="bottom-nav-conversations"]').trigger('click');
        expect(wrapper.find('[data-testid="conversation-drawer"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('opens Memórias and Configurações from the Mais menu', async () => {
        mockViewport(360);
        const wrapper = await mountLayout({}, '/', { attach: true });

        expect(wrapper.find('[data-testid="bottom-nav-more-menu"]').exists()).toBe(false);

        await wrapper.get('[data-testid="bottom-nav-more"]').trigger('click');
        const menu = wrapper.get('[data-testid="bottom-nav-more-menu"]');
        expect(menu.findAll('[role="menuitem"]').map((item) => item.text())).toEqual(['Memórias', 'Configurações']);
        expect(wrapper.get('[data-testid="bottom-nav-more"]').attributes('aria-expanded')).toBe('true');

        await menu.get('[data-testid="bottom-nav-memories"]').trigger('click');
        await vi.waitFor(() => expect(wrapper.find('[data-testid="bottom-nav-more-menu"]').exists()).toBe(false));

        wrapper.unmount();
    });

    it('loads the pending approvals once on mobile when authenticated', async () => {
        mockViewport(360);
        const { calls } = mockFetch({ 'GET /api/v1/approvals?status=pending': { body: { data: [] } } });
        useAuthStore().user = makeUser() as never;

        const wrapper = await mountLayout();
        await nextTick();

        expect(calls.filter((call) => call.url === '/api/v1/approvals?status=pending')).toHaveLength(1);

        wrapper.unmount();
    });

    it('does not load approvals on tablet/desktop or without a session, and loads after a late login', async () => {
        mockViewport(1280);
        const { calls } = mockFetch({});
        useAuthStore().user = makeUser() as never;
        const desktop = await mountLayout();
        await nextTick();
        desktop.unmount();

        mockViewport(360);
        useAuthStore().user = null;
        const wrapper = await mountLayout();
        await nextTick();

        expect(calls.some((call) => call.url.startsWith('/api/v1/approvals'))).toBe(false);

        useAuthStore().user = makeUser() as never;
        await nextTick();

        expect(calls.filter((call) => call.url === '/api/v1/approvals?status=pending')).toHaveLength(1);

        wrapper.unmount();
    });

    it('opens the agents sheet from the mobile header as a HudSheet', async () => {
        mockViewport(360);
        const wrapper = await mountLayout();
        const sectionTitles = () => wrapper.get('[data-testid="hud-sheet"]').findAll('h2').map((title) => title.text());

        await wrapper.get('[data-testid="header-agents-mobile"]').trigger('click');
        expect(wrapper.get('[data-testid="hud-sheet"]').attributes('role')).toBe('dialog');
        expect(sectionTitles()).toContain('Agentes ativos');
        expect(sectionTitles()).not.toContain('Tarefas');

        // `Esc` closes the sheet.
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();
        expect(wrapper.find('[data-testid="hud-sheet"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('prevents horizontal scroll on narrow screens', async () => {
        const wrapper = await mountLayout();

        expect(wrapper.get('[data-testid="hud-layout"]').classes()).toEqual(
            expect.arrayContaining(['overflow-x-hidden', 'w-full']),
        );
    });
});

describe('HudLayout connection edge state', () => {
    it('shows the offline edge state and disables sending while disconnected', async () => {
        const { useConnectionStore } = await import('@/stores/connection');
        const wrapper = await mountLayout({ footer: '<input id="composer" />' });
        const connection = useConnectionStore();

        expect(wrapper.find('[data-testid="disconnected-banner"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="layout-footer-controls"]').attributes('disabled')).toBeUndefined();

        connection.state = 'disconnected';
        await wrapper.vm.$nextTick();

        const offline = wrapper.get('[data-testid="disconnected-banner"] [data-testid="edge-state"]');
        expect(offline.attributes('data-tone')).toBe('offline');
        expect(offline.text()).toContain('Sem conexão');
        expect(wrapper.get('[data-testid="layout-footer-controls"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="bottom-nav-microphone"]').attributes('disabled')).toBeDefined();

        connection.state = 'connected';
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="disconnected-banner"]').exists()).toBe(false);
    });

    it('paints the abyss background with a glass header', async () => {
        const wrapper = await mountLayout();

        expect(wrapper.get('[data-testid="hud-layout"]').classes()).toContain('abyss-bg');
        expect(wrapper.get('[data-testid="layout-header"]').classes()).toContain('glass');
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
