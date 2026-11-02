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
});
