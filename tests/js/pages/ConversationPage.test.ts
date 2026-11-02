import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ConversationPage from '@/pages/ConversationPage.vue';
import type { Message } from '@/types/models';
import { mockFetch } from '../helpers/fetch';

function makeConversation(id: string, title: string) {
    return { id, title, last_activity_at: '2026-11-02T10:00:00.000000Z', ai_model_id: null, model: null };
}

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

function showEnvelope(messages: Message[], nextCursor: string | null = null) {
    return { data: makeConversation('c1', 'Plano'), messages, next_cursor: nextCursor };
}

describe('ConversationPage', () => {
    it('loads the conversation (newest 50 reversed) and renders the messages', async () => {
        mockFetch({
            'GET /api/v1/conversations/c1': {
                body: showEnvelope([
                    makeMessage({ id: 'a1', role: 'assistant', source: null, content: 'resposta' }),
                    makeMessage({ id: 'u1', content: 'pergunta' }),
                ]),
            },
        });

        const wrapper = mount(ConversationPage, { props: { id: 'c1' } });
        await flushPromises();

        expect(wrapper.get('[data-testid="conversation-title"]').text()).toBe('Plano');
        expect(wrapper.findAll('[data-testid="message-bubble-user"]')).toHaveLength(1);
        expect(wrapper.findAll('[data-testid="message-bubble-assistant"]')).toHaveLength(1);

        wrapper.unmount();
    });

    it('loads older messages through the cursor when asked', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/conversations/c1': {
                body: showEnvelope([makeMessage({ id: 'u2', content: 'segunda' })], 'u2'),
            },
            'GET /api/v1/conversations/c1?before=u2': {
                body: showEnvelope([makeMessage({ id: 'u1', content: 'primeira' })], null),
            },
        });

        const wrapper = mount(ConversationPage, { props: { id: 'c1' } });
        await flushPromises();

        await wrapper.get('[data-testid="conversation-load-older"] button').trigger('click');
        await flushPromises();

        expect(calls.some((call) => call.url === '/api/v1/conversations/c1?before=u2')).toBe(true);
        expect(wrapper.findAll('[data-testid="message-bubble-user"]')).toHaveLength(2);
        expect(wrapper.find('[data-testid="conversation-load-older"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('retries a failed assistant message by re-sending the original user content', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/conversations/c1': {
                body: showEnvelope([
                    makeMessage({ id: 'a1', role: 'assistant', source: null, status: 'failed', content: 'Desculpe.' }),
                    makeMessage({ id: 'u1', content: 'pergunta original' }),
                ]),
            },
            'POST /api/v1/conversations/c1/messages': { status: 202, body: { data: { message_id: 'm9', conversation_id: 'c1' } } },
        });

        const wrapper = mount(ConversationPage, { props: { id: 'c1' } });
        await flushPromises();

        await wrapper.get('[data-testid="message-retry"]').trigger('click');
        await flushPromises();

        const post = calls.find((call) => call.method === 'POST' && call.url === '/api/v1/conversations/c1/messages');
        expect(post).toBeDefined();
        expect(JSON.parse(post!.init!.body as string)).toEqual({ content: 'pergunta original', source: 'text' });
        // The failed bubble was removed in favor of the new attempt.
        expect(wrapper.find('[data-testid="message-retry"]').exists()).toBe(false);

        wrapper.unmount();
    });
});
