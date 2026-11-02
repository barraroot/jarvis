import { defineStore } from 'pinia';
import { watch } from 'vue';
import { translate } from '@/i18n';
import { API_PREFIX, HttpError, http } from '@/lib/http';
import { useConnectionStore } from '@/stores/connection';
import { useConversationsStore } from '@/stores/conversations';
import { useToastStore } from '@/stores/toast';
import type { Message, MessageSource } from '@/types/models';

/** Echo-compatible channel; the real one comes from `useRealtimeConnection`. */
export interface MessageChannel {
    listen(event: string, callback: (payload: unknown) => void): unknown;
}

interface MessageCreatedPayload {
    message?: Message | null;
    conversation?: { id?: unknown; title?: unknown } | null;
}

interface ChunkPayload {
    message_id?: unknown;
    delta?: unknown;
    index?: unknown;
    conversation_id?: unknown;
}

/** Streamed text per assistant message, placed by chunk index (out-of-order safe). */
const chunkBuffers = new Map<string, (string | undefined)[]>();

/**
 * Sending and realtime application of messages on `private-user.{id}`:
 * optimistic inserts, `conversation.message.created`, `assistant.response.chunk`
 * (indexed, out-of-order tolerant), `assistant.response.completed` and
 * `conversation.title.changed`. Chunks of another conversation never leak into
 * the open one — the chunk carries `conversation_id` (Phase 8).
 */
export const useMessagesStore = defineStore('messages', () => {
    const conversations = useConversationsStore();
    const connection = useConnectionStore();
    const toasts = useToastStore();

    let optimisticCounter = 0;

    function isActiveConversation(id: unknown): boolean {
        return conversations.activeId !== null && String(id) === String(conversations.activeId);
    }

    function applyConversationTitle(conversation: MessageCreatedPayload['conversation']): void {
        if (conversation === undefined || conversation === null || typeof conversation.title !== 'string') {
            return;
        }

        if (typeof conversation.id === 'string' || typeof conversation.id === 'number') {
            conversations.applyTitle(conversation.id, conversation.title);
        }
    }

    /**
     * Sends a user message: optimistic insert, then `POST` (202). The optimistic
     * bubble is promoted to the real id by the response or by
     * `conversation.message.created`, whichever arrives first. While the
     * realtime link is down the send is rejected with a toast — nothing local
     * is generated (US-2.4).
     */
    async function send(content: string, source: MessageSource): Promise<void> {
        if (connection.isDisconnected) {
            toasts.push(translate('messages.offline'), { variant: 'warning' });

            throw new Error('offline');
        }

        const conversationId = conversations.activeId;

        if (conversationId === null) {
            toasts.push(translate('messages.noConversation'), { variant: 'warning' });

            throw new Error('no active conversation');
        }

        const localId = `optimistic-${Date.now()}-${optimisticCounter++}`;

        const optimistic: Message = {
            id: localId,
            localId,
            conversation_id: conversationId,
            role: 'user',
            source,
            status: 'completed',
            content,
            payload: null,
            ai_model_id: null,
            created_at: new Date().toISOString(),
        };

        conversations.appendMessage(optimistic);

        try {
            const response = await http.post<{ data: { message_id: string; conversation_id: string } }>(
                `${API_PREFIX}/conversations/${conversationId}/messages`,
                { content, source },
            );

            conversations.confirmLocal(localId, String(response.data.message_id));
        } catch (error) {
            conversations.markLocalFailed(localId);

            if (!(error instanceof HttpError) || (error.status !== 429 && error.status !== 401)) {
                const message =
                    error instanceof HttpError && error.serverMessage !== null
                        ? error.serverMessage
                        : translate('errors.network');

                toasts.push(message, { variant: 'danger' });
            }

            throw error;
        }
    }

    /** "Tentar novamente": re-sends the original content of a failed message (US-3.1). */
    async function retry(message: Message): Promise<void> {
        const all = conversations.messages;
        const index = all.findIndex((item) => item.id === message.id);

        if (index === -1) {
            return;
        }

        let content: string;
        let source: MessageSource;

        if (message.role === 'assistant') {
            // The assistant bubble failed: replay the user message that triggered it.
            let userMessage: Message | null = null;

            for (let cursor = index - 1; cursor >= 0; cursor--) {
                if (all[cursor]?.role === 'user') {
                    userMessage = all[cursor]!;
                    break;
                }
            }

            if (userMessage === null) {
                return;
            }

            content = userMessage.content;
            source = userMessage.source ?? 'text';
        } else {
            content = message.content;
            source = message.source ?? 'text';
        }

        conversations.removeMessage(message.id);

        await send(content, source);
    }

    function onMessageCreated(payload: unknown): void {
        const event = payload as MessageCreatedPayload | null;
        const message = event?.message;

        if (message === undefined || message === null || !isActiveConversation(message.conversation_id)) {
            return;
        }

        // Dedupe by id: a re-delivered event never duplicates the message.
        if (conversations.messages.some((item) => item.id === String(message.id))) {
            applyConversationTitle(event?.conversation);

            return;
        }

        // The matching optimistic bubble is promoted to the real id.
        const local = conversations.messages.find(
            (item) => item.localId !== undefined && item.content === message.content,
        );

        if (local !== undefined && local.localId !== undefined) {
            conversations.confirmLocal(local.localId, String(message.id));
        } else {
            conversations.appendMessage({ ...message, id: String(message.id) });
        }

        conversations.bump(message.conversation_id);
        applyConversationTitle(event?.conversation);
    }

    function onChunk(payload: unknown): void {
        const event = payload as ChunkPayload | null;

        if (event === null || (typeof event.message_id !== 'number' && typeof event.message_id !== 'string')) {
            return;
        }

        if (typeof event.delta !== 'string' || typeof event.index !== 'number' || !isActiveConversation(event.conversation_id)) {
            return;
        }

        const id = String(event.message_id);
        let message = conversations.messages.find((item) => item.id === id);

        // First chunk creates the streaming bubble; the indicator disappears.
        if (message === undefined) {
            message = {
                id,
                conversation_id: String(event.conversation_id),
                role: 'assistant',
                source: null,
                status: 'running',
                content: '',
                payload: null,
                ai_model_id: null,
                created_at: new Date().toISOString(),
            };
            conversations.appendMessage(message);
        }

        // Indexed buffer: chunks may arrive out of order; the text is rebuilt in order.
        const buffer = chunkBuffers.get(id) ?? [];
        buffer[event.index] = event.delta;
        chunkBuffers.set(id, buffer);
        message.content = buffer.filter((delta): delta is string => delta !== undefined).join('');

        if (message.status !== 'running') {
            message.status = 'running';
        }
    }

    function onCompleted(payload: unknown): void {
        const event = payload as MessageCreatedPayload | null;
        const message = event?.message;

        if (message === undefined || message === null || !isActiveConversation(message.conversation_id)) {
            return;
        }

        const id = String(message.id);
        chunkBuffers.delete(id);
        conversations.replaceMessage({ ...message, id });
        applyConversationTitle(event?.conversation);
    }

    function onTitleChanged(payload: unknown): void {
        applyConversationTitle((payload as MessageCreatedPayload | null)?.conversation);
    }

    /** Binds the `private-user.{id}` channel events (called by `useRealtimeConnection`). */
    function bind(channel: MessageChannel): void {
        channel.listen('conversation.message.created', onMessageCreated);
        channel.listen('assistant.response.chunk', onChunk);
        channel.listen('assistant.response.completed', onCompleted);
        channel.listen('conversation.title.changed', onTitleChanged);
    }

    // Reconnection restore (US-2.4): back from a drop, reload the open conversation
    // so pending/running bubbles are replaced by their actual server state.
    watch(
        () => connection.state,
        (state, previous) => {
            if (previous === 'disconnected' && state === 'connected') {
                const id = conversations.activeId;

                if (id !== null) {
                    conversations.open(id).catch(() => undefined);
                }
            }
        },
    );

    // Streaming buffers belong to a conversation; switching discards them.
    watch(
        () => conversations.activeId,
        () => {
            chunkBuffers.clear();
        },
    );

    return { send, retry, bind };
});
