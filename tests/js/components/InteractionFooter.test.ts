import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import InteractionFooter from '@/components/hud/InteractionFooter.vue';
import { MESSAGE_COUNTER_THRESHOLD } from '@/components/hud/constants';
import { useConnectionStore } from '@/stores/connection';

function mountFooter(props: Record<string, unknown> = {}) {
    return mount(InteractionFooter, { props });
}

async function typeText(wrapper: ReturnType<typeof mountFooter>, text: string) {
    await wrapper.find('textarea').setValue(text);
    await wrapper.vm.$nextTick();
}

describe('InteractionFooter', () => {
    it('renders the translated placeholder and sends on Enter', async () => {
        const wrapper = mountFooter();

        expect(wrapper.find('textarea').attributes('placeholder')).toBe('Digite sua mensagem…');

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

    it('the microphone button mutes instead of starting push-to-talk', async () => {
        const wrapper = mountFooter();
        const button = wrapper.get('[data-testid="footer-mute"]');

        expect(button.attributes('aria-label')).toBe('Mutar microfone');
        expect(button.attributes('aria-pressed')).toBe('false');

        await button.trigger('click');
        expect(wrapper.emitted('mute')).toHaveLength(1);

        await wrapper.setProps({ muted: true });
        expect(wrapper.get('[data-testid="footer-mute"]').attributes('aria-label')).toBe('Desmutar microfone');
        expect(wrapper.get('[data-testid="footer-mute"]').attributes('aria-pressed')).toBe('true');

        wrapper.unmount();
    });

    it('disables the microphone button while voice is unavailable, keeping text chat', async () => {
        const wrapper = mountFooter({ voiceUnavailable: true });

        expect(wrapper.get('[data-testid="footer-mute"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="footer-mute"]').attributes('title')).toBe('Voz indisponível');

        await typeText(wrapper, 'oi');
        await wrapper.trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('send')).toEqual([[{ content: 'oi', source: 'text' }]]);

        wrapper.unmount();
    });

    it('has no push-to-talk: Tab keeps its native behaviour and no capture or speech controls render', async () => {
        const wrapper = mountFooter();

        const event = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
        wrapper.element.dispatchEvent(event);
        await wrapper.vm.$nextTick();

        expect(event.defaultPrevented).toBe(false);
        expect(wrapper.emitted('voice')).toBeUndefined();
        expect(wrapper.find('[data-testid="footer-capture"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="footer-speech-controls"]').exists()).toBe(false);

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

    it('uses chamfered controls with 44 px targets and no rounded corners', async () => {
        const wrapper = mountFooter();

        await wrapper.get('[data-testid="footer-commands-toggle"]').trigger('click');

        const controls = [
            wrapper.get('[data-testid="footer-send"]'),
            wrapper.get('[data-testid="footer-mute"]'),
            wrapper.get('[data-testid="footer-commands-toggle"]'),
            ...wrapper.findAll('[data-testid^="footer-command-"]'),
        ];

        for (const control of controls) {
            expect(control.classes(), control.attributes('data-testid')).toEqual(expect.arrayContaining(['chamfer-sm', 'min-h-11']));
        }

        expect(wrapper.find('textarea').classes()).toContain('chamfer-sm');
        expect(wrapper.html()).not.toMatch(/\brounded(-[a-z]+)?\b/);

        wrapper.unmount();
    });
});
