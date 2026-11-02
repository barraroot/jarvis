import { describe, expect, it } from 'vitest';
import { useConversationsStore } from '@/stores/conversations';
import { mockFetch } from '../helpers/fetch';

function makeConversation(id: string, title: string) {
    return { id, title, last_activity_at: '2026-11-02T10:00:00.000000Z' };
}

function listEnvelope(conversations: ReturnType<typeof makeConversation>[], currentPage: number, lastPage: number) {
    return { data: conversations, meta: { current_page: currentPage, last_page: lastPage } };
}

describe('conversations store', () => {
    it('lists page 1 and appends the next page on loadMore', async () => {
        mockFetch({
            'GET /api/v1/conversations?page=1': { body: listEnvelope([makeConversation('a', 'Alfa')], 1, 2) },
            'GET /api/v1/conversations?page=2': { body: listEnvelope([makeConversation('b', 'Beta')], 2, 2) },
        });
        const store = useConversationsStore();

        await store.list(1);
        expect(store.conversations).toEqual([makeConversation('a', 'Alfa')]);
        expect(store.hasMorePages).toBe(true);

        await store.loadMore();
        expect(store.conversations).toHaveLength(2);
        expect(store.hasMorePages).toBe(false);
    });

    it('creates a conversation and unshifts it', async () => {
        mockFetch({ 'POST /api/v1/conversations': { body: { data: makeConversation('n', 'Nova conversa') } } });
        const store = useConversationsStore();

        const created = await store.create();

        expect(created.id).toBe('n');
        expect(store.conversations.map((conversation) => conversation.id)).toEqual(['n']);
    });

    it('renames through PATCH and updates the local copy', async () => {
        const { calls } = mockFetch({ 'PATCH /api/v1/conversations/a': { body: { data: makeConversation('a', 'Novo título') } } });
        const store = useConversationsStore();
        store.conversations.push(makeConversation('a', 'Antigo'));

        await store.rename('a', 'Novo título');

        expect(calls.some((call) => call.method === 'PATCH' && call.url === '/api/v1/conversations/a')).toBe(true);
        expect(store.conversations[0]?.title).toBe('Novo título');
    });

    it('deletes through DELETE and removes the local copy', async () => {
        const { calls } = mockFetch({ 'DELETE /api/v1/conversations/a': { status: 204 } });
        const store = useConversationsStore();
        store.conversations.push(makeConversation('a', 'Alfa'));

        await store.remove('a');

        expect(calls.some((call) => call.method === 'DELETE' && call.url === '/api/v1/conversations/a')).toBe(true);
        expect(store.conversations).toHaveLength(0);
    });

    it('opens a conversation with its newest 50 messages reversed and the cursor', async () => {
        const newest = { id: 'm2', role: 'user', content: 'segunda' };
        const oldest = { id: 'm1', role: 'user', content: 'primeira' };

        mockFetch({
            'GET /api/v1/conversations/c1': {
                body: {
                    data: makeConversation('c1', 'Plano'),
                    messages: [newest, oldest],
                    next_cursor: 'm1',
                },
            },
        });
        const store = useConversationsStore();

        await store.open('c1');

        expect(store.active?.title).toBe('Plano');
        expect(store.activeId).toBe('c1');
        expect(store.messages.map((message) => message.id)).toEqual(['m1', 'm2']);
        expect(store.nextCursor).toBe('m1');
        expect(store.hasOlder).toBe(true);
    });

    it('loadOlder prepends the previous page using the cursor', async () => {
        mockFetch({
            'GET /api/v1/conversations/c1': {
                body: {
                    data: makeConversation('c1', 'Plano'),
                    messages: [{ id: 'm2', role: 'user', content: 'segunda' }],
                    next_cursor: 'm2',
                },
            },
            'GET /api/v1/conversations/c1?before=m2': {
                body: {
                    data: makeConversation('c1', 'Plano'),
                    messages: [{ id: 'm1', role: 'user', content: 'primeira' }],
                    next_cursor: null,
                },
            },
        });
        const store = useConversationsStore();

        await store.open('c1');
        await store.loadOlder();

        expect(store.messages.map((message) => message.id)).toEqual(['m1', 'm2']);
        expect(store.hasOlder).toBe(false);
    });

    it('closes the open conversation and clears its messages', async () => {
        mockFetch({
            'GET /api/v1/conversations/c1': {
                body: { data: makeConversation('c1', 'Plano'), messages: [{ id: 'm1', role: 'user', content: 'oi' }], next_cursor: null },
            },
        });
        const store = useConversationsStore();

        await store.open('c1');
        store.close();

        expect(store.activeId).toBeNull();
        expect(store.active).toBeNull();
        expect(store.messages).toHaveLength(0);
        expect(store.hasOlder).toBe(false);
    });

    it('switches the conversation model through PATCH and updates active and list copies', async () => {
        const { calls } = mockFetch({
            'PATCH /api/v1/conversations/c1': {
                body: {
                    data: {
                        ...makeConversation('c1', 'Plano'),
                        ai_model_id: 'model-7',
                        model: { id: 'model-7', name: 'GPT', provider: 'openai', is_default: false },
                    },
                },
            },
        });
        const store = useConversationsStore();

        store.conversations.push(makeConversation('c1', 'Plano'));
        store.activeId = 'c1';
        store.active = makeConversation('c1', 'Plano');

        await store.setModel('c1', 'model-7');

        expect(JSON.parse(calls[0]!.init!.body as string)).toEqual({ ai_model_id: 'model-7' });
        expect(store.active?.ai_model_id).toBe('model-7');
        expect(store.conversations[0]?.model?.name).toBe('GPT');
    });

    it('applyTitle updates the drawer item and the open conversation', () => {
        const store = useConversationsStore();

        store.conversations.push(makeConversation('c1', 'Nova conversa'));
        store.activeId = 'c1';
        store.active = makeConversation('c1', 'Nova conversa');

        store.applyTitle('c1', 'Plano de voo');

        expect(store.conversations[0]?.title).toBe('Plano de voo');
        expect(store.active?.title).toBe('Plano de voo');
    });
});
