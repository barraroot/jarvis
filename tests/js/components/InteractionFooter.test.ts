import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import InteractionFooter from '@/components/hud/InteractionFooter.vue';
import { MESSAGE_COUNTER_THRESHOLD } from '@/components/hud/constants';
import { useConnectionStore } from '@/stores/connection';

afterEach(() => {
    delete (window as unknown as Record<string, unknown>).SpeechRecognition;
});

function mountFooter() {
    return mount(InteractionFooter);
}

async function typeText(wrapper: ReturnType<typeof mountFooter>, text: string) {
    await wrapper.find('textarea').setValue(text);
    await wrapper.vm.$nextTick();
}

describe('InteractionFooter', () => {
    it('renders the translated placeholder and sends on Enter', async () => {
        const wrapper = mountFooter();

        expect(wrapper.find('textarea').attributes('placeholder')).toBe('Digite, ou pressione Tab para falar…');

        await typeText(wrapper, '  olá  ');
        await wrapper.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('send')).toEqual([[{ content: 'olá', source: 'text' }]]);
        expect(wrapper.find('textarea').element.value).toBe('');

        wrapper.unmount();
    });

    it('keeps Shift+Enter as a line break instead of sending', async () => {
        const wrapper = mountFooter();

        await typeText(wrapper, 'linha');
        await wrapper.trigger('keydown', { key: 'Enter', shiftKey: true });

        expect(wrapper.emitted('send')).toBeUndefined();

        wrapper.unmount();
    });

    it('shows the counter above 7.500 chars and blocks sending beyond the limit', async () => {
        const wrapper = mountFooter();

        await typeText(wrapper, 'x'.repeat(MESSAGE_COUNTER_THRESHOLD + 1));
        expect(wrapper.get('[data-testid="footer-counter"]').text()).toContain('8.000');

        await typeText(wrapper, 'x'.repeat(8001));
        await wrapper.trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('send')).toBeUndefined();
        expect(wrapper.get('[data-testid="footer-send"]').attributes('disabled')).toBeDefined();

        wrapper.unmount();
    });

    it('disables the voice button without speech recognition support', () => {
        const wrapper = mountFooter();

        expect(wrapper.get('[data-testid="footer-voice"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="footer-voice"]').attributes('title')).toContain('não suportada');

        wrapper.unmount();
    });

    it('maps Tab to voice and Escape to interrupt', async () => {
        Object.defineProperty(window, 'SpeechRecognition', { configurable: true, value: class {} });
        const wrapper = mountFooter();

        await wrapper.trigger('keydown', { key: 'Tab' });
        expect(wrapper.emitted('voice')).toHaveLength(1);

        await wrapper.trigger('keydown', { key: 'Escape' });
        expect(wrapper.emitted('interrupt')).toHaveLength(1);

        wrapper.unmount();
    });

    it('keeps native Tab behaviour inside the composer instead of triggering voice', async () => {
        Object.defineProperty(window, 'SpeechRecognition', { configurable: true, value: class {} });
        const wrapper = mountFooter();

        const event = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
        wrapper.find('textarea').element.dispatchEvent(event);
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('voice')).toBeUndefined();
        expect(event.defaultPrevented).toBe(false);

        wrapper.unmount();
    });

    it('opens the commands menu on / and prefixes text for inline commands', async () => {
        const wrapper = mountFooter();

        await wrapper.trigger('keydown', { key: '/' });
        expect(wrapper.find('[data-testid="footer-commands-menu"]').exists()).toBe(true);

        await wrapper.get('[data-testid="footer-command-remember"]').trigger('click');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('textarea').element.value).toBe('/lembrar ');
        expect(wrapper.find('[data-testid="footer-commands-menu"]').exists()).toBe(false);

        await wrapper.get('[data-testid="footer-commands-toggle"]').trigger('click');
        await wrapper.get('[data-testid="footer-command-new"]').trigger('click');
        expect(wrapper.emitted('command')).toEqual([[{ type: 'new' }]]);

        wrapper.unmount();
    });

    it('shows the capture waveform while LISTENING or SPEAKING', async () => {
        const wrapper = mountFooter();

        expect(wrapper.find('[data-testid="footer-capture"]').exists()).toBe(false);

        const { useAssistantStore } = await import('@/stores/assistant');
        useAssistantStore().setState('LISTENING');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="footer-capture"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('warns and disables while disconnected', async () => {
        const wrapper = mountFooter();
        const connection = useConnectionStore();

        expect(wrapper.find('[role="alert"]').exists()).toBe(false);

        connection.state = 'disconnected';
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[role="alert"]').text()).toContain('Sem conexão');
        expect(wrapper.find('textarea').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="footer-send"]').attributes('disabled')).toBeDefined();

        connection.state = 'connected';
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);

        wrapper.unmount();
    });
});
