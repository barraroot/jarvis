import { nextTick } from 'vue';
import { flushPromises } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { useConnectionStore } from '@/stores/connection';
import { useConversationsStore } from '@/stores/conversations';
import { useMessagesStore, type MessageChannel } from '@/stores/messages';
import { useToastStore } from '@/stores/toast';
import type { Message } from '@/types/models';
import { mockFetch } from '../helpers/fetch';

interface FakeChannel extends MessageChannel {
    emit(event: string, payload: unknown): void;
}

/**
 * Echo-like channel capturing listeners; the test emits events like Reverb would.
 * Events use `broadcastAs` names, so Echo only delivers them to `.`-prefixed listeners.
 */
function makeChannel(): FakeChannel {
    const listeners = new Map<string, (payload: unknown) => void>();

    return {
        listen(event: string, callback: (payload: unknown) => void) {
            listeners.set(event, callback);

            return undefined;
        },
        emit(event: string, payload: unknown) {
            listeners.get(`.${event}`)?.(payload);
        },
    };
}

function makeConversation(id: string, title = 'Conversa') {
    return { id, title, last_activity_at: '2026-11-02T10:00:00.000000Z', ai_model_id: null, model: null };
}

function showEnvelope(messages: Message[] = [], nextCursor: string | null = null) {
    return { data: makeConversation('c1'), messages, next_cursor: nextCursor };
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

describe('messages store', () => {
    it('rebuilds streamed text in index order when chunks arrive out of order', async () => {
        mockFetch({ 'GET /api/v1/conversations/c1': { body: showEnvelope() } });
        const channel = makeChannel();
        const store = useMessagesStore();
        const conversations = useConversationsStore();

        await conversations.open('c1');
        store.bind(channel);

        channel.emit('assistant.response.chunk', { message_id: 'a1', delta: 'três ', index: 2, conversation_id: 'c1' });
        channel.emit('assistant.response.chunk', { message_id: 'a1', delta: 'um ', index: 0, conversation_id: 'c1' });
        channel.emit('assistant.response.chunk', { message_id: 'a1', delta: 'dois ', index: 1, conversation_id: 'c1' });

        expect(conversations.messages).toHaveLength(1);
        expect(conversations.messages[0]?.content).toBe('um dois três ');
        expect(conversations.messages[0]?.status).toBe('running');
        expect(conversations.messages[0]?.role).toBe('assistant');
    });

    it('dedupes chunk re-deliveries by index', async () => {
        mockFetch({ 'GET /api/v1/conversations/c1': { body: showEnvelope() } });
        const channel = makeChannel();
        const store = useMessagesStore();
        const conversations = useConversationsStore();

        await conversations.open('c1');
        store.bind(channel);

        channel.emit('assistant.response.chunk', { message_id: 'a1', delta: 'oi', index: 0, conversation_id: 'c1' });
        channel.emit('assistant.response.chunk', { message_id: 'a1', delta: 'oi', index: 0, conversation_id: 'c1' });

        expect(conversations.messages[0]?.content).toBe('oi');
    });

    it('never leaks chunks of another conversation into the open one', async () => {
        mockFetch({ 'GET /api/v1/conversations/c1': { body: showEnvelope() } });
        const channel = makeChannel();
        const store = useMessagesStore();
        const conversations = useConversationsStore();

        await conversations.open('c1');
        store.bind(channel);

        // Another conversation streams the same unknown message id: ignored.
        channel.emit('assistant.response.chunk', { message_id: 'x1', delta: 'segredo', index: 0, conversation_id: 'c2' });

        expect(conversations.messages).toHaveLength(0);

        // A chunk of the open conversation still creates the streaming bubble.
        channel.emit('assistant.response.chunk', { message_id: 'x1', delta: 'local', index: 0, conversation_id: 'c1' });

        expect(conversations.messages).toHaveLength(1);
        expect(conversations.messages[0]?.content).toBe('local');
    });

    it('replaces the streaming bubble with the final message on completed', async () => {
        mockFetch({ 'GET /api/v1/conversations/c1': { body: showEnvelope() } });
        const channel = makeChannel();
        const store = useMessagesStore();
        const conversations = useConversationsStore();

        await conversations.open('c1');
        store.bind(channel);

        channel.emit('assistant.response.chunk', { message_id: 'a1', delta: 'parte', index: 0, conversation_id: 'c1' });
        channel.emit('assistant.response.completed', {
            message: makeMessage({ id: 'a1', role: 'assistant', status: 'completed', content: 'parte final', source: null }),
            conversation: { id: 'c1', title: 'Conversa' },
        });

        expect(conversations.messages).toHaveLength(1);
        expect(conversations.messages[0]?.content).toBe('parte final');
        expect(conversations.messages[0]?.status).toBe('completed');
        expect(conversations.messages[0]?.localId).toBeUndefined();
    });

    it('ignores completed events of another conversation', async () => {
        mockFetch({ 'GET /api/v1/conversations/c1': { body: showEnvelope() } });
        const channel = makeChannel();
        const store = useMessagesStore();
        const conversations = useConversationsStore();

        await conversations.open('c1');
        store.bind(channel);

        channel.emit('assistant.response.completed', {
            message: makeMessage({ id: 'a2', conversation_id: 'c2', role: 'assistant', source: null }),
            conversation: { id: 'c2', title: 'Outra' },
        });

        expect(conversations.messages).toHaveLength(0);
    });

    it('sends a message optimistically and confirms it with the POST response', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/conversations/c1': { body: showEnvelope() },
            'POST /api/v1/conversations/c1/messages': { status: 202, body: { data: { message_id: 'm9', conversation_id: 'c1' } } },
        });
        const store = useMessagesStore();
        const conversations = useConversationsStore();

        await conversations.open('c1');
        await store.send('Olá JARVIS', 'text');

        const post = calls.find((call) => call.method === 'POST' && call.url === '/api/v1/conversations/c1/messages');
        expect(post).toBeDefined();
        expect(JSON.parse(post!.init!.body as string)).toEqual({ content: 'Olá JARVIS', source: 'text' });

        expect(conversations.messages).toHaveLength(1);
        expect(conversations.messages[0]?.id).toBe('m9');
        expect(conversations.messages[0]?.localId).toBeUndefined();
        expect(conversations.messages[0]?.content).toBe('Olá JARVIS');
    });

    it('promotes the optimistic bubble on message.created and dedupes re-deliveries', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/conversations/c1': { body: showEnvelope() },
            'POST /api/v1/conversations/c1/messages': { status: 202, body: { data: { message_id: 'm1', conversation_id: 'c1' } } },
        });
        const channel = makeChannel();
        const store = useMessagesStore();
        const conversations = useConversationsStore();

        await conversations.open('c1');
        store.bind(channel);

        const sending = store.send('Olá JARVIS', 'text');
        // The broadcast arrives while the POST is still in flight.
        channel.emit('conversation.message.created', {
            message: makeMessage({ id: 'm1', content: 'Olá JARVIS' }),
            conversation: { id: 'c1', title: 'Conversa' },
        });
        // A re-delivered event must not duplicate the message.
        channel.emit('conversation.message.created', {
            message: makeMessage({ id: 'm1', content: 'Olá JARVIS' }),
            conversation: { id: 'c1', title: 'Conversa' },
        });
        await sending;

        expect(calls.filter((call) => call.method === 'POST').length).toBe(1);
        expect(conversations.messages).toHaveLength(1);
        expect(conversations.messages[0]?.id).toBe('m1');
    });

    it('rejects send while disconnected with an i18n toast and no POST', async () => {
        const { calls } = mockFetch({ 'GET /api/v1/conversations/c1': { body: showEnvelope() } });
        const store = useMessagesStore();
        const conversations = useConversationsStore();
        const connection = useConnectionStore();

        await conversations.open('c1');
        connection.markDisconnected();

        await expect(store.send('Olá', 'text')).rejects.toThrow('offline');

        expect(calls.some((call) => call.method === 'POST')).toBe(false);
        const toasts = useToastStore().toasts;
        expect(toasts.some((toast) => toast.message.includes('Sem conexão'))).toBe(true);
    });

    it('reloads the open conversation when the connection comes back and replaces pending/running state', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/conversations/c1': () => ({
                body: calls.filter((call) => call.url === '/api/v1/conversations/c1').length >= 2
                    ? showEnvelope([makeMessage({ id: 'a1', role: 'assistant', status: 'completed', content: 'final', source: null })])
                    : showEnvelope([makeMessage({ id: 'a1', role: 'assistant', status: 'running', content: 'parcial', source: null })]),
            }),
        });
        const conversations = useConversationsStore();
        const connection = useConnectionStore();

        useMessagesStore();

        await conversations.open('c1');
        expect(conversations.messages[0]?.status).toBe('running');

        connection.markDisconnected();
        await nextTick();
        connection.markConnected();
        await flushPromises();

        expect(calls.filter((call) => call.url === '/api/v1/conversations/c1').length).toBe(2);
        expect(conversations.messages).toHaveLength(1);
        expect(conversations.messages[0]?.status).toBe('completed');
        expect(conversations.messages[0]?.content).toBe('final');
    });

    it('generates no local text while disconnected: send leaves the conversation untouched', async () => {
        const { calls } = mockFetch({ 'GET /api/v1/conversations/c1': { body: showEnvelope() } });
        const store = useMessagesStore();
        const conversations = useConversationsStore();
        const connection = useConnectionStore();

        await conversations.open('c1');
        connection.markDisconnected();

        await expect(store.send('Olá', 'text')).rejects.toThrow('offline');

        expect(conversations.messages).toHaveLength(0);
        expect(calls.some((call) => call.method === 'POST')).toBe(false);
    });

    it('updates conversation titles live from events', async () => {
        mockFetch({ 'GET /api/v1/conversations/c1': { body: showEnvelope() } });
        const channel = makeChannel();
        const store = useMessagesStore();
        const conversations = useConversationsStore();

        await conversations.open('c1');
        store.bind(channel);

        channel.emit('conversation.title.changed', { conversation: { id: 'c1', title: 'Plano de voo' } });

        expect(conversations.active?.title).toBe('Plano de voo');
        expect(conversations.conversations.find((conversation) => conversation.id === 'c1')?.title).toBe('Plano de voo');
    });
});
