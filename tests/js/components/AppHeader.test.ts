import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AppHeader from '@/components/hud/AppHeader.vue';
import { useConnectorsStore } from '@/stores/connectors';
import { useNotificationsStore } from '@/stores/notifications';
import { useTasksStore } from '@/stores/tasks';
import { useVoiceStore } from '@/stores/voice';
import type { TaskSummary } from '@/types/models';

const Blank = { render: () => null };

function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'hud', component: Blank },
            { path: '/settings', name: 'settings', component: Blank },
            { path: '/memories', name: 'memories', component: Blank },
            { path: '/activity', name: 'activity', component: Blank },
            { path: '/approvals', name: 'approvals', component: Blank },
        ],
    });
}

async function mountHeader() {
    const router = makeRouter();
    await router.push('/');
    await router.isReady();

    const wrapper = mount(AppHeader, { global: { plugins: [router] }, attachTo: document.body });

    return { wrapper, router };
}

function task(id: string, status: TaskSummary['status']): TaskSummary {
    return { id, title: `Tarefa ${id}`, status, progress: 0 };
}

function pressAlt(digit: string): void {
    document.dispatchEvent(new KeyboardEvent('keydown', { key: digit, code: `Digit${digit}`, altKey: true, bubbles: true }));
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('AppHeader', () => {
    it('renders the logo with the voice status line below it', async () => {
        const { wrapper } = await mountHeader();
        const voice = useVoiceStore();
        const tasks = useTasksStore();

        expect(wrapper.get('[data-testid="header-brand"]').text()).toContain('J.A.R.V.I.S.');
        expect(wrapper.get('[data-testid="header-status-line"]').text()).toBe('Aguardando "Jarvis"');

        voice.setPhase('listening');
        voice.setCountdown(57);
        tasks.upsert(task('1', 'running'));
        await wrapper.vm.$nextTick();
        expect(wrapper.get('[data-testid="header-status-line"]').text()).toBe('Ouvindo · 57s · 1 tarefa');

        voice.setPhase('speaking');
        voice.setCountdown(null);
        await wrapper.vm.$nextTick();
        expect(wrapper.get('[data-testid="header-status-line"]').text()).toBe('Falando · 1 tarefa');

        tasks.remove('1');
        voice.setPhase('user_speaking');
        await wrapper.vm.$nextTick();
        expect(wrapper.get('[data-testid="header-status-line"]').text()).toBe('Você está falando…');

        wrapper.unmount();
    });

    it('shows "Voz indisponível" with the reason as tooltip, including another tab', async () => {
        const { wrapper } = await mountHeader();
        const voice = useVoiceStore();

        voice.markUnavailable('other_tab');
        await wrapper.vm.$nextTick();

        const line = wrapper.get('[data-testid="header-status-line"]');
        expect(line.text()).toBe('Voz indisponível');
        expect(line.attributes('title')).toBe('Voz ativa em outra aba');
        expect(wrapper.get('[data-testid="header-sleep"]').attributes('disabled')).toBeDefined();

        voice.markUnavailable('no_realtime');
        await wrapper.vm.$nextTick();
        expect(line.attributes('title')).toBe('Nenhum modelo de voz em tempo real configurado');

        wrapper.unmount();
    });

    it('shows the centered clock with the long pt-BR date and advances it every minute', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 10, 1, 9, 41, 30));

        const { wrapper } = await mountHeader();

        expect(wrapper.get('[data-testid="header-clock-time"]').text()).toBe('09:41');
        expect(wrapper.get('[data-testid="header-clock-date"]').text()).toBe('DOMINGO · 1 DE NOVEMBRO');

        await vi.advanceTimersByTimeAsync(30_000);
        expect(wrapper.get('[data-testid="header-clock-time"]').text()).toBe('09:42');

        await vi.advanceTimersByTimeAsync(60_000);
        expect(wrapper.get('[data-testid="header-clock-time"]').text()).toBe('09:43');

        wrapper.unmount();
    });

    it('toggles between Dormir and Acordar and emits sleep/wake', async () => {
        const { wrapper } = await mountHeader();
        const voice = useVoiceStore();
        const button = wrapper.get('[data-testid="header-sleep"]');

        expect(button.text()).toBe('Acordar');
        await button.trigger('click');
        expect(wrapper.emitted('wake')).toHaveLength(1);

        voice.setPhase('listening');
        await wrapper.vm.$nextTick();
        expect(button.text()).toBe('Dormir');
        await button.trigger('click');
        expect(wrapper.emitted('sleep')).toHaveLength(1);

        wrapper.unmount();
    });

    it('mutes and unmutes the microphone', async () => {
        const { wrapper } = await mountHeader();
        const voice = useVoiceStore();
        const button = wrapper.get('[data-testid="header-mic"]');

        expect(button.text()).toBe('Mutar microfone');
        await button.trigger('click');
        expect(wrapper.emitted('microphone')).toHaveLength(1);

        voice.setMuted(true);
        await wrapper.vm.$nextTick();
        expect(button.text()).toBe('Desmutar microfone');
        expect(button.attributes('aria-pressed')).toBe('true');

        wrapper.unmount();
    });

    it('shows agent and connector badges only when greater than zero', async () => {
        const { wrapper } = await mountHeader();
        const tasks = useTasksStore();
        const connectors = useConnectorsStore();

        expect(wrapper.find('[data-testid="header-agents-badge"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="header-connectors-badge"]').exists()).toBe(false);

        tasks.upsert(task('1', 'running'));
        connectors.setCounts(3, 2);
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[data-testid="header-agents-badge"]').text()).toBe('1');
        expect(wrapper.get('[data-testid="header-connectors-badge"]').text()).toBe('2');

        tasks.remove('1');
        connectors.setCounts(3, 0);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="header-agents-badge"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="header-connectors-badge"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('shows the connectors tooltip with the Alt 2 shortcut in plural and singular', async () => {
        const { wrapper } = await mountHeader();
        const connectors = useConnectorsStore();
        const tooltip = () => wrapper.get('[data-testid="header-connectors-tooltip"]');

        connectors.setCounts(1, 2);
        await wrapper.get('[data-testid="header-connectors"]').trigger('mouseenter');

        expect(tooltip().isVisible()).toBe(true);
        expect(tooltip().text()).toContain('Conectores');
        expect(wrapper.get('[data-testid="header-connectors-shortcut"]').text()).toBe('Alt 2');
        expect(wrapper.get('[data-testid="header-connectors-attention"]').text()).toBe('2 conectores precisam de atenção');

        connectors.setCounts(1, 1);
        await wrapper.vm.$nextTick();
        expect(wrapper.get('[data-testid="header-connectors-attention"]').text()).toBe('1 conector precisa de atenção');

        connectors.setCounts(1, 0);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="header-connectors-attention"]').exists()).toBe(false);

        await wrapper.get('[data-testid="header-connectors"]').trigger('mouseleave');
        expect(tooltip().isVisible()).toBe(false);

        await wrapper.get('[data-testid="header-connectors"]').trigger('focus');
        expect(tooltip().isVisible()).toBe(true);

        wrapper.unmount();
    });

    it('opens the connectors with Alt+2 and the tooltip', async () => {
        const { wrapper, router } = await mountHeader();

        pressAlt('2');
        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('settings'));

        expect(router.currentRoute.value.hash).toBe('#connectors');
        expect(wrapper.get('[data-testid="header-connectors-tooltip"]').isVisible()).toBe(true);

        wrapper.unmount();
    });

    it('opens agents with Alt+1 and conversations with Alt+3', async () => {
        const { wrapper } = await mountHeader();

        pressAlt('1');
        pressAlt('3');

        expect(wrapper.emitted('agents')).toHaveLength(1);
        expect(wrapper.emitted('conversations')).toHaveLength(1);

        await wrapper.get('[data-testid="header-agents"]').trigger('click');
        await wrapper.get('[data-testid="header-conversations"]').trigger('click');
        expect(wrapper.emitted('agents')).toHaveLength(2);
        expect(wrapper.emitted('conversations')).toHaveLength(2);

        wrapper.unmount();
    });

    it('lists approvals, memories, activity, settings and logout in the menu', async () => {
        const { wrapper } = await mountHeader();
        const notifications = useNotificationsStore();

        notifications.setUnreadCount(3);
        await wrapper.get('[data-testid="header-menu"]').trigger('click');

        for (const item of ['approvals', 'memories', 'activity', 'settings', 'logout']) {
            expect(wrapper.find(`[data-testid="header-menu-${item}"]`).exists(), item).toBe(true);
        }

        expect(wrapper.get('[data-testid="header-menu-approvals"]').text()).toContain('3');

        wrapper.unmount();
    });

    it('collapses the remaining actions into the mobile more menu', async () => {
        const { wrapper } = await mountHeader();

        expect(wrapper.find('[data-testid="header-more-menu"]').exists()).toBe(false);

        await wrapper.get('[data-testid="header-more"]').trigger('click');
        expect(wrapper.find('[data-testid="header-more-menu"]').exists()).toBe(true);

        await wrapper.get('[data-testid="header-more-sleep"]').trigger('click');
        expect(wrapper.emitted('wake')).toHaveLength(1);
        expect(wrapper.find('[data-testid="header-more-menu"]').exists()).toBe(false);

        wrapper.unmount();
    });
});
