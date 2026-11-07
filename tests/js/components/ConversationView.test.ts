import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ConversationView from '@/components/hud/ConversationView.vue';
import { useAssistantStore } from '@/stores/assistant';
import type { Message } from '@/types/models';

function makeMessage(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        conversation_id: 'c1',
        role: 'user',
        source: 'text',
        status: 'completed',
        content: 'Olá',
        payload: null,
        ai_model_id: null,
        created_at: '2026-11-02T10:00:00.000000Z',
        ...overrides,
    };
}

function mountView(messages: Message[] = [], hasOlder = false) {
    return mount(ConversationView, { props: { messages, hasOlder } });
}

describe('ConversationView', () => {
    it('shows the i18n greeting while the conversation is empty and idle', () => {
        const wrapper = mountView();

        expect(wrapper.find('[data-testid="conversation-greeting"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Saudações');
    });

    it('shows the processing indicator while THINKING and no chunk arrived yet', () => {
        useAssistantStore().setState('THINKING');

        const wrapper = mountView();

        expect(wrapper.find('[data-testid="processing-indicator"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="conversation-greeting"]').exists()).toBe(false);
    });

    it('replaces the processing indicator once the streaming bubble receives chunks', () => {
        useAssistantStore().setState('THINKING');

        const wrapper = mountView([makeMessage({ id: 'a1', role: 'assistant', source: null, status: 'running', content: 'parcial' })]);

        expect(wrapper.find('[data-testid="processing-indicator"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="message-streaming-caret"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="message-bubble-assistant"]').text()).toContain('parcial');
    });

    it('renders a blinking caret only for the streaming assistant message', () => {
        const wrapper = mountView([
            makeMessage({ id: 'u1', role: 'user', content: 'pergunta' }),
            makeMessage({ id: 'a1', role: 'assistant', source: null, status: 'running', content: 'resposta' }),
            makeMessage({ id: 'a2', role: 'assistant', source: null, status: 'completed', content: 'feito' }),
        ]);

        const carets = wrapper.findAll('[data-testid="message-streaming-caret"]');

        expect(carets).toHaveLength(1);
        expect(carets[0]?.text()).toContain('▍');
    });

    it('shows an error icon and retry button on a failed message and emits the message on retry', async () => {
        const failed = makeMessage({ id: 'a1', role: 'assistant', source: null, status: 'failed', content: 'Desculpe, falhei.' });
        const wrapper = mountView([failed]);

        expect(wrapper.find('[data-testid="message-error-icon"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="message-retry"]').text()).toBe('Tentar novamente');

        await wrapper.get('[data-testid="message-retry"]').trigger('click');

        const emitted = wrapper.emitted('retry');
        expect(emitted).toHaveLength(1);
        expect((emitted?.[0]?.[0] as Message).id).toBe('a1');
    });

    it('offers "carregar anteriores" while older messages exist behind the cursor', async () => {
        const wrapper = mountView([makeMessage()], true);

        expect(wrapper.find('[data-testid="conversation-load-older"]').exists()).toBe(true);

        await wrapper.get('[data-testid="conversation-load-older"] button').trigger('click');

        expect(wrapper.emitted('loadOlder')).toHaveLength(1);

        const withoutOlder = mountView([makeMessage()]);
        expect(withoutOlder.find('[data-testid="conversation-load-older"]').exists()).toBe(false);
    });
    it('renders letter avatars and chamfered bubbles without rounded corners', () => {
        const wrapper = mountView([
            makeMessage({ id: 'u1', content: 'pergunta' }),
            makeMessage({ id: 'a1', role: 'assistant', source: null, content: 'resposta' }),
        ]);

        expect(wrapper.get('[data-testid="message-bubble-user"] [data-testid="message-avatar"]').text()).toBe('V');
        expect(wrapper.get('[data-testid="message-bubble-assistant"] [data-testid="message-avatar"]').text()).toBe('J');
        expect(wrapper.get('[data-testid="message-bubble-user"] [data-testid="message-body"]').classes()).toContain('chamfer-md');
        expect(wrapper.find('[data-testid="conversation-messages"]').html()).not.toMatch(/\brounded(-[a-z0-9]+)?\b/);
    });
});
