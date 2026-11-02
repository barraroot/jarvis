import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { API_PREFIX, http } from '@/lib/http';
import type { AiModelSummary, Conversation, Message } from '@/types/models';

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

/** `GET /api/v1/conversations/{id}` returns the conversation plus a message page. */
interface ConversationShowEnvelope {
    data: Conversation;
    messages: Message[];
    next_cursor: string | null;
}

/**
 * Conversation history (drawer list) plus the open conversation and its
 * messages. The Phase 7 API contract: `GET/POST /api/v1/conversations`,
 * `PATCH/DELETE /{id}`, `GET /{id}` with the latest 50 messages and a
 * backward cursor (`?before=<message_id>`).
 *
 * The messages store (`stores/messages.ts`) feeds the list through the
 * mutators below while streaming replies arrive over Echo.
 */
export const useConversationsStore = defineStore('conversations', () => {
    const conversations = ref<Conversation[]>([]);
    const page = ref(1);
    const hasMorePages = ref(false);
    const loading = ref(false);

    /** The conversation shown by the conversation view (route `/conversations/:id`). */
    const activeId = ref<string | null>(null);
    const active = ref<Conversation | null>(null);
    /** Display order: oldest first. The API returns pages newest first. */
    const messages = ref<Message[]>([]);
    const nextCursor = ref<string | null>(null);
    const opening = ref(false);
    const loadingOlder = ref(false);

    const hasOlder = computed(() => nextCursor.value !== null);

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

    /** Creates a conversation and makes it the active one (send() follows immediately). */
    async function create(): Promise<Conversation> {
        const response = await http.post<ConversationEnvelope>(`${API_PREFIX}/conversations`);

        conversations.value.unshift(response.data);
        active.value = response.data;
        activeId.value = response.data.id;
        messages.value = [];
        nextCursor.value = null;

        return response.data;
    }

    /**
     * Opens a conversation: latest 50 messages (newest first on the wire) plus
     * the backward cursor. Optimistic messages of the same conversation survive
     * the reload; everything else is replaced.
     */
    async function open(id: string): Promise<Conversation> {
        opening.value = true;

        try {
            const response = await http.get<ConversationShowEnvelope>(`${API_PREFIX}/conversations/${id}`);
            const fetched = [...response.messages].reverse();
            const locals = messages.value.filter(
                (message) => message.localId !== undefined && String(message.conversation_id) === String(id),
            );

            active.value = response.data;
            activeId.value = id;
            messages.value = [...fetched, ...locals];
            nextCursor.value = response.next_cursor;

            const inList = conversations.value.find((conversation) => String(conversation.id) === String(id));

            if (inList !== undefined) {
                Object.assign(inList, response.data);
            } else {
                conversations.value.unshift(response.data);
            }

            return response.data;
        } finally {
            opening.value = false;
        }
    }

    /** Loads the page of messages older than the current cursor and prepends it. */
    async function loadOlder(before: string | null = nextCursor.value): Promise<void> {
        if (before === null || activeId.value === null || loadingOlder.value) {
            return;
        }

        loadingOlder.value = true;

        try {
            const response = await http.get<ConversationShowEnvelope>(
                `${API_PREFIX}/conversations/${activeId.value}?before=${before}`,
            );

            messages.value = [...[...response.messages].reverse(), ...messages.value];
            nextCursor.value = response.next_cursor;
        } finally {
            loadingOlder.value = false;
        }
    }

    /** Leaves the open conversation (back to the HUD greeting). */
    function close(): void {
        activeId.value = null;
        active.value = null;
        messages.value = [];
        nextCursor.value = null;
    }

    async function rename(id: string, title: string): Promise<void> {
        await http.patch(`${API_PREFIX}/conversations/${id}`, { title });

        const conversation = conversations.value.find((item) => String(item.id) === String(id));

        if (conversation !== undefined) {
            conversation.title = title;
        }

        if (activeId.value !== null && String(activeId.value) === String(id) && active.value !== null) {
            active.value.title = title;
        }
    }

    async function remove(id: string): Promise<void> {
        await http.delete(`${API_PREFIX}/conversations/${id}`);
        conversations.value = conversations.value.filter((item) => String(item.id) !== String(id));

        if (activeId.value !== null && String(activeId.value) === String(id)) {
            close();
        }
    }

    /** Switches the conversation model (US-3.5); a 422 bubbles up to the selector. */
    async function setModel(id: string, aiModelId: string | null): Promise<Conversation> {
        const response = await http.patch<ConversationEnvelope>(`${API_PREFIX}/conversations/${id}`, {
            ai_model_id: aiModelId,
        });

        applyModel(id, response.data.ai_model_id ?? null, response.data.model ?? null);

        return response.data;
    }

    function applyModel(id: string, aiModelId: string | null, model: AiModelSummary | null): void {
        const inList = conversations.value.find((conversation) => String(conversation.id) === String(id));

        if (inList !== undefined) {
            inList.ai_model_id = aiModelId;
            inList.model = model;
        }

        if (activeId.value !== null && String(activeId.value) === String(id) && active.value !== null) {
            active.value.ai_model_id = aiModelId;
            active.value.model = model;
        }
    }

    /** Live title update coming from `conversation.*` events (Phase 8). */
    function applyTitle(conversationId: string | number, title: string): void {
        const id = String(conversationId);
        const inList = conversations.value.find((conversation) => String(conversation.id) === id);

        if (inList !== undefined) {
            inList.title = title;
        }

        if (activeId.value !== null && String(activeId.value) === id && active.value !== null) {
            active.value.title = title;
        }
    }

    /** Moves a conversation to the top of the drawer after new activity. */
    function bump(conversationId: string | number): void {
        const index = conversations.value.findIndex((conversation) => String(conversation.id) === String(conversationId));

        if (index > 0) {
            const [conversation] = conversations.value.splice(index, 1);

            if (conversation !== undefined) {
                conversations.value.unshift(conversation);
            }
        }
    }

    /** Mutators used by the messages store (optimistic inserts, streaming, events). */
    function appendMessage(message: Message): void {
        messages.value.push(message);
    }

    function replaceMessage(message: Message): void {
        const index = messages.value.findIndex((item) => item.id === message.id);

        if (index === -1) {
            messages.value.push(message);
        } else {
            messages.value[index] = { ...message };
        }
    }

    /** Promotes an optimistic message to its real id; drops it when the real one arrived first. */
    function confirmLocal(localId: string, realId: string): void {
        const index = messages.value.findIndex((item) => item.localId === localId);

        if (index === -1) {
            return;
        }

        if (messages.value.some((item) => item.id === realId)) {
            messages.value.splice(index, 1);

            return;
        }

        messages.value[index] = { ...messages.value[index]!, id: realId, localId: undefined };
    }

    function markLocalFailed(localId: string): void {
        const index = messages.value.findIndex((item) => item.localId === localId);

        if (index !== -1) {
            messages.value[index] = { ...messages.value[index]!, status: 'failed' };
        }
    }

    function removeMessage(id: string): void {
        messages.value = messages.value.filter((message) => message.id !== id);
    }

    return {
        conversations,
        page,
        hasMorePages,
        loading,
        activeId,
        active,
        messages,
        nextCursor,
        opening,
        loadingOlder,
        hasOlder,
        list,
        loadMore,
        create,
        open,
        loadOlder,
        close,
        rename,
        remove,
        setModel,
        applyTitle,
        bump,
        appendMessage,
        replaceMessage,
        confirmLocal,
        markLocalFailed,
        removeMessage,
    };
});
