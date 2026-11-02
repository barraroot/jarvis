<script setup lang="ts">
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ConversationView from '@/components/hud/ConversationView.vue';
import ModelSelector from '@/components/hud/ModelSelector.vue';
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

/** Opening the route loads the conversation (latest 50) plus the backward cursor. */
watch(
    () => props.id,
    (id) => {
        conversations.open(id).catch(() => {
            toasts.push(t('errors.unexpected'), { variant: 'danger' });
        });
    },
    { immediate: true },
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
        <div class="flex items-center justify-between gap-3 border-b border-line pb-2" data-testid="conversation-header">
            <h1 class="hud-label min-w-0 truncate text-sm text-cyan hud-glow" data-testid="conversation-title">
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
