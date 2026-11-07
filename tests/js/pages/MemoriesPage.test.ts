import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import MemoriesPage from '@/pages/MemoriesPage.vue';
import { useMemoriesStore } from '@/stores/memories';
import type { Memory, MemoryStatus } from '@/types/models';
import { mockFetch, type MockRoutes } from '../helpers/fetch';

function makeMemory(id: string, status: MemoryStatus, overrides: Partial<Memory> = {}): Memory {
    return {
        id,
        content: `Memória ${id}`,
        status,
        category: 'preference',
        source_conversation_id: null,
        source_message_id: null,
        confirmed_at: status === 'confirmed' ? '2026-11-02T10:00:00Z' : null,
        last_used_at: null,
        created_at: '2026-11-02T10:00:00Z',
        updated_at: '2026-11-02T10:00:00Z',
        ...overrides,
    };
}

function listEnvelope(memories: Memory[], lastPage = 1) {
    return {
        data: memories,
        meta: { current_page: 1, last_page: lastPage },
        summary: { confirmed: 0, hypotheses: 0 },
    };
}

async function mountPage(routes: MockRoutes) {
    const { calls } = mockFetch(routes);
    const wrapper = mount(MemoriesPage, { global: { stubs: { RouterLink: true } } });
    await flushPromises();

    return { wrapper, calls };
}

describe('MemoriesPage', () => {
    it('opens on the confirmed tab and requests confirmed memories', async () => {
        const { wrapper, calls } = await mountPage({
            'GET /api/v1/memories?page=1&status=confirmed': { body: listEnvelope([makeMemory('1', 'confirmed')]) },
        });

        expect(calls.map((call) => call.url)).toContain('/api/v1/memories?page=1&status=confirmed');
        expect(wrapper.get('[data-testid="memories-tab-confirmed"]').attributes('aria-selected')).toBe('true');
        expect(wrapper.findAll('[data-testid="memory-item"]')).toHaveLength(1);

        wrapper.unmount();
    });

    it('requests hypotheses when the Hipóteses tab is activated', async () => {
        const { wrapper, calls } = await mountPage({
            'GET /api/v1/memories?page=1&status=confirmed': { body: listEnvelope([]) },
            'GET /api/v1/memories?page=1&status=hypothesis': { body: listEnvelope([makeMemory('2', 'hypothesis')]) },
        });

        await wrapper.get('[data-testid="memories-tab-hypothesis"]').trigger('click');
        await vi.waitFor(() => expect(calls.at(-1)?.url).toBe('/api/v1/memories?page=1&status=hypothesis'));
        await flushPromises();

        expect(wrapper.get('[data-testid="memories-tab-hypothesis"]').text()).toBe('Hipóteses');
        expect(wrapper.findAll('[data-testid="memory-item"]')).toHaveLength(1);

        wrapper.unmount();
    });

    it('requests the selected category', async () => {
        const { wrapper, calls } = await mountPage({
            'GET /api/v1/memories?page=1&status=confirmed': { body: listEnvelope([]) },
            'GET /api/v1/memories?page=1&status=confirmed&category=project': { body: listEnvelope([makeMemory('3', 'confirmed', { category: 'project' })]) },
        });

        await wrapper.get('[data-testid="memories-category-project"]').trigger('click');
        await vi.waitFor(() => expect(calls.at(-1)?.url).toBe('/api/v1/memories?page=1&status=confirmed&category=project'));
        await flushPromises();

        expect(wrapper.get('[data-testid="memories-category-project"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.get('[data-testid="memory-category"]').text()).toBe('Projeto');

        wrapper.unmount();
    });

    it('draws confirmed memories solid and hypotheses dashed, with hypothesis actions', async () => {
        const { wrapper } = await mountPage({
            'GET /api/v1/memories?page=1&status=confirmed': { body: listEnvelope([makeMemory('1', 'confirmed'), makeMemory('2', 'hypothesis')]) },
        });

        const [confirmed, hypothesis] = wrapper.findAll('[data-testid="memory-item"]');

        expect(confirmed?.classes()).toContain('border-solid');
        expect(confirmed?.classes()).not.toContain('border-dashed');
        expect(hypothesis?.classes()).toContain('border-dashed');
        expect(hypothesis?.classes()).not.toContain('border-solid');

        expect(confirmed?.find('[data-testid="memory-confirm"]').exists()).toBe(false);
        expect(hypothesis?.get('[data-testid="memory-confirm"]').text()).toBe('Confirmar');
        expect(hypothesis?.get('[data-testid="memory-discard"]').text()).toBe('Descartar');

        for (const item of [confirmed, hypothesis]) {
            expect(item?.get('[data-testid="memory-edit"]').text()).toBe('Editar');
            expect(item?.get('[data-testid="memory-delete"]').text()).toBe('Excluir');
        }

        wrapper.unmount();
    });

    it('keeps the existing search placeholder and omits the board-only views', async () => {
        const { wrapper } = await mountPage({
            'GET /api/v1/memories?page=1&status=confirmed': { body: listEnvelope([makeMemory('1', 'confirmed')]) },
        });

        expect(wrapper.get('[data-testid="memories-search"] input').attributes('placeholder')).toBe('Buscar memórias…');

        for (const text of ['Curto prazo', 'Longo prazo', 'Constelação', 'Busca semântica', 'Semântica']) {
            expect(wrapper.text()).not.toContain(text);
        }

        wrapper.unmount();
    });

    it('sends the search query with the active tab', async () => {
        const { wrapper, calls } = await mountPage({
            'GET /api/v1/memories?page=1&status=confirmed': { body: listEnvelope([]) },
            'GET /api/v1/memories?page=1&status=confirmed&q=caf%C3%A9': { body: listEnvelope([]) },
        });

        await wrapper.get('[data-testid="memories-search"] input').setValue('café');
        await wrapper.get('[data-testid="memories-filter-apply"]').trigger('click');
        await flushPromises();

        expect(calls.at(-1)?.url).toBe('/api/v1/memories?page=1&status=confirmed&q=caf%C3%A9');

        wrapper.unmount();
    });

    it('shows the empty state once loaded without items, never while loading', async () => {
        const { wrapper } = await mountPage({
            'GET /api/v1/memories?page=1&status=confirmed': { body: listEnvelope([]) },
        });

        expect(wrapper.get('[data-testid="empty-state-title"]').text()).toBe('Nenhuma memória ainda');
        expect(wrapper.get('[data-testid="empty-state-text"]').text()).toContain('Hipóteses chegam tracejadas');

        useMemoriesStore().loading = true;
        await nextTick();

        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('does not show the empty state before the first load settles', async () => {
        mockFetch({ 'GET /api/v1/memories?page=1&status=confirmed': { body: listEnvelope([]) } });
        const wrapper = mount(MemoriesPage, { global: { stubs: { RouterLink: true } } });

        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(false);

        await flushPromises();
        wrapper.unmount();
    });

    it('confirms a hypothesis through the API', async () => {
        const { wrapper, calls } = await mountPage({
            'GET /api/v1/memories?page=1&status=confirmed': { body: listEnvelope([makeMemory('2', 'hypothesis')]) },
            'POST /api/v1/memories/2/confirm': { body: { data: makeMemory('2', 'confirmed') } },
        });

        await wrapper.get('[data-testid="memory-confirm"]').trigger('click');
        await flushPromises();

        expect(calls.some((call) => call.method === 'POST' && call.url === '/api/v1/memories/2/confirm')).toBe(true);

        wrapper.unmount();
    });

    it('uses no rounded corners', async () => {
        const { wrapper } = await mountPage({
            'GET /api/v1/memories?page=1&status=confirmed': { body: listEnvelope([makeMemory('1', 'confirmed')]) },
        });

        const rounded = wrapper.findAll('[class*="rounded"]').filter((node) => node.attributes('data-dot') === undefined);

        expect(rounded.map((node) => node.html())).toEqual([]);

        wrapper.unmount();
    });
});
