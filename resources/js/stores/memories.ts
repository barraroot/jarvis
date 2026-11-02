import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { API_PREFIX, http } from '@/lib/http';
import type { Memory } from '@/types/models';

interface PaginationMeta {
    current_page: number;
    last_page: number;
}

interface MemorySummary {
    confirmed: number;
    hypotheses: number;
}

interface MemoryListEnvelope {
    data: Memory[];
    meta: PaginationMeta;
    summary: MemorySummary;
}

interface MemoryEnvelope {
    data: Memory;
}

export type MemoryCategory = 'preference' | 'project' | 'context' | 'learning' | 'fact';

/**
 * The operator's long-term memories (US-6.4): paginated list with search and
 * filters, inline edit, hard delete, and the confirm/discard decision for
 * hypotheses. `summary` feeds the Memory Insights panel (US-6.2).
 */
export const useMemoriesStore = defineStore('memories', () => {
    const memories = ref<Memory[]>([]);
    const page = ref(1);
    const hasMorePages = ref(false);
    const loading = ref(false);
    const summary = ref<MemorySummary>({ confirmed: 0, hypotheses: 0 });
    const loaded = ref(false);

    const statusFilter = ref<string | null>(null);
    const categoryFilter = ref<MemoryCategory | null>(null);
    const search = ref('');

    const hasActiveFilters = computed(
        () => statusFilter.value !== null || categoryFilter.value !== null || search.value.trim() !== '',
    );

    function buildQuery(pageNumber: number): string {
        const params = new URLSearchParams({ page: String(pageNumber) });

        if (statusFilter.value !== null) {
            params.set('status', statusFilter.value);
        }

        if (categoryFilter.value !== null) {
            params.set('category', categoryFilter.value);
        }

        if (search.value.trim() !== '') {
            params.set('q', search.value.trim());
        }

        return `${API_PREFIX}/memories?${params.toString()}`;
    }

    async function list(pageNumber: number = 1): Promise<Memory[]> {
        loading.value = true;

        try {
            const response = await http.get<MemoryListEnvelope>(buildQuery(pageNumber));

            if (pageNumber === 1) {
                memories.value = response.data;
            } else {
                memories.value.push(...response.data);
            }

            page.value = response.meta.current_page;
            hasMorePages.value = response.meta.current_page < response.meta.last_page;
            summary.value = response.summary;
            loaded.value = true;

            return response.data;
        } finally {
            loading.value = false;
        }
    }

    async function loadMore(): Promise<void> {
        if (hasMorePages.value && !loading.value) {
            await list(page.value + 1);
        }
    }

    /** Applies the current filters and reloads from the first page. */
    async function refresh(): Promise<Memory[]> {
        return list(1);
    }

    async function create(content: string, category: MemoryCategory): Promise<Memory> {
        const response = await http.post<MemoryEnvelope>(`${API_PREFIX}/memories`, { content, category });

        memories.value.unshift(response.data);
        summary.value.confirmed += response.data.status === 'confirmed' ? 1 : 0;
        summary.value.hypotheses += response.data.status === 'hypothesis' ? 1 : 0;

        return response.data;
    }

    async function update(id: string, content: string): Promise<Memory> {
        const response = await http.patch<MemoryEnvelope>(`${API_PREFIX}/memories/${id}`, { content });

        const index = memories.value.findIndex((item) => item.id === id);

        if (index !== -1) {
            memories.value[index] = { ...response.data };
        }

        return response.data;
    }

    async function remove(id: string): Promise<void> {
        await http.delete(`${API_PREFIX}/memories/${id}`);

        const index = memories.value.findIndex((item) => item.id === id);

        if (index !== -1) {
            const [removed] = memories.value.splice(index, 1);

            if (removed !== undefined) {
                if (removed.status === 'confirmed') {
                    summary.value.confirmed = Math.max(0, summary.value.confirmed - 1);
                } else {
                    summary.value.hypotheses = Math.max(0, summary.value.hypotheses - 1);
                }
            }
        }
    }

    async function confirm(id: string): Promise<Memory> {
        const response = await http.post<MemoryEnvelope>(`${API_PREFIX}/memories/${id}/confirm`);

        applyReplacement(response.data);

        summary.value.confirmed += 1;
        summary.value.hypotheses = Math.max(0, summary.value.hypotheses - 1);

        return response.data;
    }

    async function discard(id: string): Promise<void> {
        await http.post(`${API_PREFIX}/memories/${id}/discard`);

        await removeFromState(id);
        summary.value.hypotheses = Math.max(0, summary.value.hypotheses - 1);
    }

    /** Local removal without an API call (used by discard). */
    async function removeFromState(id: string): Promise<void> {
        memories.value = memories.value.filter((item) => item.id !== id);
    }

    function applyReplacement(memory: Memory): void {
        const index = memories.value.findIndex((item) => item.id === memory.id);

        if (index !== -1) {
            memories.value[index] = { ...memory };
        }
    }

    function setStatusFilter(status: string | null): void {
        statusFilter.value = status;
    }

    function setCategoryFilter(category: MemoryCategory | null): void {
        categoryFilter.value = category;
    }

    function setSearch(query: string): void {
        search.value = query;
    }

    /** Counts only; used by the side panel without touching the list state. */
    async function loadSummary(): Promise<MemorySummary> {
        try {
            const response = await http.get<MemoryListEnvelope>(`${API_PREFIX}/memories?page=1`);

            summary.value = response.summary;
            loaded.value = true;
        } catch {
            // The panel stays at its previous counts.
        }

        return summary.value;
    }

    return {
        memories,
        page,
        hasMorePages,
        loading,
        summary,
        loaded,
        statusFilter,
        categoryFilter,
        search,
        hasActiveFilters,
        list,
        loadMore,
        refresh,
        create,
        update,
        remove,
        confirm,
        discard,
        setStatusFilter,
        setCategoryFilter,
        setSearch,
        loadSummary,
    };
});
