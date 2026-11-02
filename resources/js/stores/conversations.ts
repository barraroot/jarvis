import { defineStore } from 'pinia';
import { ref } from 'vue';
import { API_PREFIX, http } from '@/lib/http';
import type { Conversation } from '@/types/models';

interface PaginationMeta {
    current_page: number;
    last_page: number;
}

interface ConversationListEnvelope {
    data: Conversation[];
    meta: PaginationMeta;
}

interface ConversationEnvelope {
    data: Conversation;
}

/**
 * Conversation history for the drawer, against the Phase 7 API contract
 * (`GET/POST /api/v1/conversations`, `PATCH/DELETE /{id}`). While the backend
 * routes do not exist the list stays empty — the drawer never shows fake data.
 * Phase 8 extends this store with messages (`open`, `loadOlder`).
 */
export const useConversationsStore = defineStore('conversations', () => {
    const conversations = ref<Conversation[]>([]);
    const page = ref(1);
    const hasMorePages = ref(false);
    const loading = ref(false);

    async function list(pageNumber: number = 1): Promise<Conversation[]> {
        loading.value = true;

        try {
            const response = await http.get<ConversationListEnvelope>(
                `${API_PREFIX}/conversations?page=${pageNumber}`,
            );

            if (pageNumber === 1) {
                conversations.value = response.data;
            } else {
                conversations.value.push(...response.data);
            }

            page.value = response.meta.current_page;
            hasMorePages.value = response.meta.current_page < response.meta.last_page;

            return response.data;
        } finally {
            loading.value = false;
        }
    }

    async function loadMore(): Promise<void> {
        await list(page.value + 1);
    }

    async function create(): Promise<Conversation> {
        const response = await http.post<ConversationEnvelope>(`${API_PREFIX}/conversations`);

        conversations.value.unshift(response.data);

        return response.data;
    }

    async function rename(id: string, title: string): Promise<void> {
        await http.patch(`${API_PREFIX}/conversations/${id}`, { title });

        const conversation = conversations.value.find((item) => item.id === id);

        if (conversation !== undefined) {
            conversation.title = title;
        }
    }

    async function remove(id: string): Promise<void> {
        await http.delete(`${API_PREFIX}/conversations/${id}`);
        conversations.value = conversations.value.filter((item) => item.id !== id);
    }

    return { conversations, page, hasMorePages, loading, list, loadMore, create, rename, remove };
});
