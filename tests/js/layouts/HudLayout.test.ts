import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { describe, expect, it } from 'vitest';
import { SIDEBAR_COLLAPSED_STORAGE_KEY } from '@/layouts/constants';
import HudLayout from '@/layouts/HudLayout.vue';

const Blank = { render: () => null };

async function mountLayout(slots: Record<string, string> = {}) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'hud', component: Blank },
            { path: '/settings', name: 'settings', component: Blank },
        ],
    });
    await router.push('/');

    return mount(HudLayout, { slots, global: { plugins: [router] } });
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
        const wrapper = await mountLayout();
        const sidebar = wrapper.get('[data-testid="layout-sidebar"]');

        expect(sidebar.classes()).toContain('md:w-80');
        expect(sidebar.classes()).toContain('md:flex');
        expect(wrapper.get('[data-testid="layout-footer"]').classes()).toContain('md:pl-80');
    });

    it('persists the tablet collapse state in localStorage', async () => {
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
