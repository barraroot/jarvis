<script setup lang="ts">
import { RouterView, useRoute, useRouter } from 'vue-router';
import HudToast from '@/components/ui/HudToast.vue';
import { useRealtimeConnection } from '@/composables/useRealtimeConnection';
import HudLayout from '@/layouts/HudLayout.vue';
import { useConversationsStore } from '@/stores/conversations';
import { useMessagesStore } from '@/stores/messages';

const route = useRoute();
const router = useRouter();
const conversations = useConversationsStore();
const messages = useMessagesStore();

useRealtimeConnection();

/** Footer send: from the HUD a fresh conversation is created first, then navigated to. */
async function onSend(payload: { content: string; source: 'text' | 'voice' }): Promise<void> {
    try {
        if (conversations.activeId === null) {
            const conversation = await conversations.create();

            await router.push({ name: 'conversation', params: { id: conversation.id } });
        }

        await messages.send(payload.content, payload.source);
    } catch {
        // The stores already surfaced the failure through toasts.
    }
}
</script>

<template>
    <HudLayout v-if="route.meta.requiresAuth" @send="onSend">
        <template #main>
            <RouterView />
        </template>
    </HudLayout>
    <RouterView v-else />
    <HudToast />
</template>
