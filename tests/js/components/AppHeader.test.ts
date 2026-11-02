import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { afterEach, describe, expect, it } from 'vitest';
import AppHeader from '@/components/hud/AppHeader.vue';
import { useAssistantStore } from '@/stores/assistant';
import { useConnectorsStore } from '@/stores/connectors';
import { useNotificationsStore } from '@/stores/notifications';

const Blank = { render: () => null };

async function mountHeader() {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/settings', name: 'settings', component: Blank },
            { path: '/memories', name: 'memories', component: Blank },
        ],
    });
    await router.push('/settings');
    await router.isReady();

    return mount(AppHeader, { global: { plugins: [router] } });
}

afterEach(() => {
    delete (window as unknown as Record<string, unknown>).SpeechRecognition;
});

describe('AppHeader', () => {
    it('renders the brand, tagline and the current core status', async () => {
        const wrapper = await mountHeader();

        expect(wrapper.get('[data-testid="header-brand"]').text()).toContain('J.A.R.V.I.S.');
        expect(wrapper.get('[data-testid="header-brand"]').text()).toContain('Just A Rather Very Intelligent System');
        expect(wrapper.get('[data-testid="header-status"]').text()).toBe('Ocioso');

        wrapper.unmount();
    });

    it('toggles sleep and wake through the assistant store', async () => {
        const wrapper = await mountHeader();
        const assistant = useAssistantStore();

        await wrapper.get('[data-testid="header-sleep"]').trigger('click');
        expect(assistant.state).toBe('SLEEPING');

        await wrapper.get('[data-testid="header-sleep"]').trigger('click');
        expect(assistant.state).toBe('IDLE');

        wrapper.unmount();
    });

    it('emits microphone only when speech recognition is supported', async () => {
        const unsupported = await mountHeader();
        const button = unsupported.get('[data-testid="header-mic"]');

        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('title')).toContain('não suportado');
        unsupported.unmount();

        Object.defineProperty(window, 'SpeechRecognition', { configurable: true, value: class {} });

        const supported = await mountHeader();
        await supported.get('[data-testid="header-mic"]').trigger('click');
        expect(supported.emitted('microphone')).toHaveLength(1);
        supported.unmount();
    });

    it('shows the notification counter only when there are unread items', async () => {
        const wrapper = await mountHeader();
        const notifications = useNotificationsStore();

        expect(wrapper.find('[data-testid="header-notifications-count"]').exists()).toBe(false);

        notifications.setUnreadCount(3);
        await wrapper.vm.$nextTick();
        expect(wrapper.get('[data-testid="header-notifications-count"]').text()).toBe('3');

        wrapper.unmount();
    });

    it('shows the connector indicator only when connectors exist', async () => {
        const wrapper = await mountHeader();
        const connectors = useConnectorsStore();

        expect(wrapper.find('[data-testid="header-connectors"]').exists()).toBe(false);

        connectors.setCounts(2, 1);
        await wrapper.vm.$nextTick();
        expect(wrapper.get('[data-testid="header-connectors"]').text()).toContain('2');
        expect(wrapper.get('[data-testid="header-connectors"]').text()).toContain('1');

        wrapper.unmount();
    });

    it('emits conversations when the conversations button is clicked', async () => {
        const wrapper = await mountHeader();

        await wrapper.get('[data-testid="header-conversations"]').trigger('click');
        expect(wrapper.emitted('conversations')).toHaveLength(1);

        wrapper.unmount();
    });

    it('collapses the remaining actions into the mobile more menu', async () => {
        const wrapper = await mountHeader();
        const assistant = useAssistantStore();

        expect(wrapper.find('[data-testid="header-more-menu"]').exists()).toBe(false);

        await wrapper.get('[data-testid="header-more"]').trigger('click');
        expect(wrapper.find('[data-testid="header-more-menu"]').exists()).toBe(true);

        await wrapper.get('[data-testid="header-more-sleep"]').trigger('click');
        expect(assistant.state).toBe('SLEEPING');
        expect(wrapper.find('[data-testid="header-more-menu"]').exists()).toBe(false);

        wrapper.unmount();
    });
});
