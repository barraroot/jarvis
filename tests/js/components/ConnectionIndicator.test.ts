import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ConnectionIndicator from '@/components/hud/ConnectionIndicator.vue';
import { useConnectionStore } from '@/stores/connection';

function mountIndicator() {
    return mount(ConnectionIndicator);
}

function dotClasses(wrapper: ReturnType<typeof mountIndicator>): string[] {
    return wrapper.get('[data-dot]').classes();
}

describe('ConnectionIndicator (UI-04)', () => {
    it('shows Conectado with a steady cyan dot when connected', async () => {
        const connection = useConnectionStore();
        connection.markConnected();
        const wrapper = mountIndicator();

        expect(wrapper.attributes('role')).toBe('status');
        expect(wrapper.attributes('data-state')).toBe('connected');
        expect(wrapper.get('[data-testid="connection-indicator-label"]').text()).toBe('Conectado');
        expect(wrapper.classes()).toContain('connection-indicator--connected');
        expect(dotClasses(wrapper)).toContain('bg-cyan-300');
        expect(dotClasses(wrapper)).not.toContain('connection-indicator__dot--pulse');
        expect(wrapper.get('[data-dot]').attributes('aria-hidden')).toBe('true');
    });

    it('shows Reconectando… with a pulsing amber dot while connecting', () => {
        const connection = useConnectionStore();
        connection.state = 'connecting';
        const wrapper = mountIndicator();

        expect(wrapper.attributes('role')).toBe('status');
        expect(wrapper.text()).toBe('Reconectando…');
        expect(wrapper.classes()).toContain('connection-indicator--reconnecting');
        expect(dotClasses(wrapper)).toContain('bg-amber-400');
        expect(dotClasses(wrapper)).toContain('connection-indicator__dot--pulse');
    });

    it('shows Reconectando… while a retry is scheduled even if the socket reports connected', () => {
        const connection = useConnectionStore();
        connection.state = 'connected';
        connection.nextRetryDelayMs = 4000;
        const wrapper = mountIndicator();

        expect(wrapper.text()).toBe('Reconectando…');
        expect(wrapper.attributes('data-state')).toBe('reconnecting');
    });

    it('shows Offline with a danger dot when disconnected, over a scheduled retry', () => {
        const connection = useConnectionStore();
        connection.state = 'disconnected';
        connection.nextRetryDelayMs = 2000;
        const wrapper = mountIndicator();

        expect(wrapper.attributes('role')).toBe('status');
        expect(wrapper.text()).toBe('Offline');
        expect(wrapper.classes()).toContain('connection-indicator--offline');
        expect(dotClasses(wrapper)).toContain('bg-danger-400');
        expect(dotClasses(wrapper)).not.toContain('connection-indicator__dot--pulse');
    });

    it('shows Offline when the server is unreachable (offline shell)', async () => {
        const connection = useConnectionStore();
        connection.markConnected();
        const wrapper = mountIndicator();

        connection.markServerUnreachable(1000);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toBe('Offline');
    });

    it('follows the store as the state changes', async () => {
        const connection = useConnectionStore();
        connection.state = 'disconnected';
        const wrapper = mountIndicator();

        expect(wrapper.text()).toBe('Offline');

        connection.state = 'connecting';
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toBe('Reconectando…');

        connection.markConnected();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toBe('Conectado');
    });
});
