<script setup lang="ts">
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ConversationView from '@/components/hud/ConversationView.vue';
import ModelSelector from '@/components/hud/ModelSelector.vue';
import { useConnectionStore } from '@/stores/connection';
import { useConversationsStore } from '@/stores/conversations';
import { useMessagesStore } from '@/stores/messages';
import { useToastStore } from '@/stores/toast';
import type { Message } from '@/types/models';

const props = defineProps<{
    id: string;
}>();

const { t } = useI18n();
const conversations = useConversationsStore();
const messages = useMessagesStore();
const toasts = useToastStore();
const connection = useConnectionStore();

/** Opening the route loads the conversation (latest 50) plus the backward cursor. */
function load(id: string): void {
    conversations.open(id).catch(() => {
        // Offline the banner already explains it; the load is retried on reconnection.
        if (!connection.isDisconnected) {
            toasts.push(t('errors.unexpected'), { variant: 'danger' });
        }
    });
}

watch(() => props.id, load, { immediate: true });

/** Opened while offline (PWA shell): load once the server is reachable again (US-2.4). */
watch(
    () => connection.isConnected,
    (connected) => {
        if (connected && String(conversations.activeId) !== String(props.id)) {
            load(props.id);
        }
    },
);

function onRetry(message: Message): void {
    messages.retry(message).catch(() => undefined);
}

function onLoadOlder(): void {
    conversations.loadOlder().catch(() => {
        toasts.push(t('errors.network'), { variant: 'danger' });
    });
}
</script>

<template>
    <div class="flex h-full min-h-0 flex-col gap-3" data-testid="conversation-page">
        <div class="flex items-center justify-between gap-3 border-b border-ocean-600/70 pb-2" data-testid="conversation-header">
            <h1 class="label-caps min-w-0 truncate text-sm text-cyan-300 glow" data-testid="conversation-title">
                {{ conversations.active?.title ?? '' }}
            </h1>
            <ModelSelector
                :conversation-id="id"
                :model-id="conversations.active?.ai_model_id ?? null"
            />
        </div>

        <ConversationView
            class="min-h-0 flex-1"
            :messages="conversations.messages"
            :has-older="conversations.hasOlder"
            @retry="onRetry"
            @load-older="onLoadOlder"
        />
    </div>
</template>
