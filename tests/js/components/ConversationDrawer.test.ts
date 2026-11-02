import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { nextTick } from 'vue';
import { describe, expect, it } from 'vitest';
import ConversationDrawer from '@/components/hud/ConversationDrawer.vue';
import { useConversationsStore } from '@/stores/conversations';
import { mockFetch } from '../helpers/fetch';

const Blank = { render: () => null };

function makeConversation(id: string, title: string) {
    return { id, title, last_activity_at: '2026-11-02T10:00:00.000000Z' };
}

function listEnvelope(conversations: ReturnType<typeof makeConversation>[], currentPage: number, lastPage: number) {
    return { data: conversations, meta: { current_page: currentPage, last_page: lastPage } };
}

async function mountDrawer() {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'hud', component: Blank },
            { path: '/conversations/:id', name: 'conversation', component: Blank, props: true },
        ],
    });
    await router.push('/');
    await router.isReady();

    const wrapper = mount(ConversationDrawer, {
        props: { open: true, 'onUpdate:open': (value: boolean) => wrapper.setProps({ open: value }) },
        global: { plugins: [router] },
    });

    await flushPromises();

    return { wrapper, router };
}

describe('ConversationDrawer', () => {
    it('lists conversations from the API and opens one through the router', async () => {
        mockFetch({
            'GET /api/v1/conversations?page=1': { body: listEnvelope([makeConversation('c1', 'Plano de voo')], 1, 1) },
        });
        const { wrapper, router } = await mountDrawer();

        expect(wrapper.get('[data-testid="conversation-item"]').text()).toContain('Plano de voo');

        await wrapper.get('[data-testid="conversation-open"]').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('conversation');
        expect(router.currentRoute.value.params.id).toBe('c1');

        wrapper.unmount();
    });

    it('loads the next page with "carregar mais"', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/conversations?page=1': { body: listEnvelope([makeConversation('c1', 'Um')], 1, 2) },
            'GET /api/v1/conversations?page=2': { body: listEnvelope([makeConversation('c2', 'Dois')], 2, 2) },
        });
        const { wrapper } = await mountDrawer();

        await wrapper.get('[data-testid="drawer-load-more"]').trigger('click');
        await flushPromises();

        expect(calls.some((call) => call.url === '/api/v1/conversations?page=2')).toBe(true);
        expect(wrapper.findAll('[data-testid="conversation-item"]')).toHaveLength(2);
        expect(wrapper.find('[data-testid="drawer-load-more"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('renames a conversation inline through the store', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/conversations?page=1': { body: listEnvelope([makeConversation('c1', 'Antigo')], 1, 1) },
            'PATCH /api/v1/conversations/c1': { body: { data: makeConversation('c1', 'Renomeado') } },
        });
        const { wrapper } = await mountDrawer();

        await wrapper.get('[data-testid="conversation-rename"]').trigger('click');
        await wrapper.get('[data-testid="conversation-rename-input"] input').setValue('Renomeado');
        await wrapper.get('[data-testid="conversation-rename-input"]').trigger('keydown', { key: 'Enter' });
        await flushPromises();

        expect(calls.some((call) => call.method === 'PATCH' && call.url === '/api/v1/conversations/c1')).toBe(true);
        expect(wrapper.get('[data-testid="conversation-item"]').text()).toContain('Renomeado');

        wrapper.unmount();
    });

    it('deletes a conversation after modal confirmation', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/conversations?page=1': { body: listEnvelope([makeConversation('c1', 'Descartar')], 1, 1) },
            'DELETE /api/v1/conversations/c1': { status: 204 },
        });
        const { wrapper } = await mountDrawer();

        await wrapper.get('[data-testid="conversation-delete"]').trigger('click');
        expect(wrapper.find('[data-testid="hud-modal"]').exists()).toBe(true);

        await wrapper.get('[data-testid="drawer-delete-cancel"]').trigger('click');
        expect(wrapper.findAll('[data-testid="conversation-item"]')).toHaveLength(1);

        await wrapper.get('[data-testid="conversation-delete"]').trigger('click');
        await wrapper.get('[data-testid="drawer-delete-confirm"]').trigger('click');
        await flushPromises();

        expect(calls.some((call) => call.method === 'DELETE' && call.url === '/api/v1/conversations/c1')).toBe(true);
        expect(wrapper.findAll('[data-testid="conversation-item"]')).toHaveLength(0);

        wrapper.unmount();
    });

    it('creates a new conversation and navigates to it', async () => {
        mockFetch({
            'GET /api/v1/conversations?page=1': { body: listEnvelope([], 1, 1) },
            'POST /api/v1/conversations': { body: { data: makeConversation('nova', 'Nova conversa') } },
        });
        const { wrapper, router } = await mountDrawer();

        await wrapper.get('[data-testid="drawer-new"]').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('conversation');
        expect(router.currentRoute.value.params.id).toBe('nova');

        wrapper.unmount();
    });

    it('shows the empty state when the API has no conversations', async () => {
        mockFetch({ 'GET /api/v1/conversations?page=1': { body: listEnvelope([], 1, 1) } });
        const { wrapper } = await mountDrawer();

        expect(wrapper.get('[data-testid="empty-state"]').text()).toBe('Nenhuma conversa ainda');

        wrapper.unmount();
    });

    it('navigates home when the open conversation is deleted', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/conversations?page=1': { body: listEnvelope([makeConversation('c1', 'Aberta')], 1, 1) },
            'DELETE /api/v1/conversations/c1': { status: 204 },
        });
        const { wrapper, router } = await mountDrawer();
        const store = useConversationsStore();

        store.activeId = 'c1';
        store.active = makeConversation('c1', 'Aberta');

        await wrapper.get('[data-testid="conversation-delete"]').trigger('click');
        await wrapper.get('[data-testid="drawer-delete-confirm"]').trigger('click');
        await flushPromises();

        expect(calls.some((call) => call.method === 'DELETE' && call.url === '/api/v1/conversations/c1')).toBe(true);
        expect(router.currentRoute.value.name).toBe('hud');
        expect(store.activeId).toBeNull();

        wrapper.unmount();
    });

    it('updates the listed title live when a title event changes it', async () => {
        mockFetch({
            'GET /api/v1/conversations?page=1': { body: listEnvelope([makeConversation('c1', 'Nova conversa')], 1, 1) },
        });
        const { wrapper } = await mountDrawer();
        const store = useConversationsStore();

        store.applyTitle('c1', 'Plano de voo');
        await nextTick();

        expect(wrapper.get('[data-testid="conversation-item"]').text()).toContain('Plano de voo');

        wrapper.unmount();
    });
});
