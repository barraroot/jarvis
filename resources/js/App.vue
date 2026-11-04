<script setup lang="ts">
import { onMounted, watch } from 'vue';
import { RouterView, useRoute, useRouter } from 'vue-router';
import HudToast from '@/components/ui/HudToast.vue';
import TaskDetail from '@/components/hud/TaskDetail.vue';
import UpdatePrompt from '@/components/hud/UpdatePrompt.vue';
import { bindAgentEvents } from '@/composables/useAgentEvents';
import { bindApprovalEvents } from '@/composables/useApprovalEvents';
import { bindConnectorEvents } from '@/composables/useConnectorEvents';
import { bindNotificationEvents } from '@/composables/useNotificationEvents';
import { bindTaskEvents } from '@/composables/useTaskEvents';
import { useOfflineShell } from '@/composables/useOfflineShell';
import { useRealtimeConnection } from '@/composables/useRealtimeConnection';
import { useRealtimeVoice } from '@/composables/useRealtimeVoice';
import { bindToolEvents } from '@/composables/useToolEvents';
import { bindVisualizationEvents } from '@/composables/useVisualizationEvents';
import HudLayout from '@/layouts/HudLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { useConnectorsStore } from '@/stores/connectors';
import { useConversationsStore } from '@/stores/conversations';
import { useMessagesStore } from '@/stores/messages';
import { usePreferencesStore } from '@/stores/preferences';

const route = useRoute();
const router = useRouter();
const conversations = useConversationsStore();
const messages = useMessagesStore();
const preferences = usePreferencesStore();
const connectors = useConnectorsStore();
const auth = useAuthStore();

/**
 * Continuous voice over OpenAI Realtime (RF-01..RF-15): the wake word is
 * detected locally and audio only reaches the provider inside the listening
 * window. Without Realtime the HUD is text-only (RF-08a).
 */
const realtimeVoice = useRealtimeVoice();
const voice = realtimeVoice.voice;

useRealtimeConnection([
    (channel) => realtimeVoice.bind(channel),
    bindVisualizationEvents,
    bindToolEvents,
    bindAgentEvents,
    bindApprovalEvents,
    bindNotificationEvents,
    bindTaskEvents,
    bindConnectorEvents,
]);

useOfflineShell();

onMounted(() => {
    void preferences.ensureLoaded().catch(() => undefined);
});

/**
 * Header connector indicator (US-9.4): loaded once the operator is signed in.
 * After an offline boot this also reloads the preferences that failed to load.
 */
watch(
    () => auth.isAuthenticated,
    (authenticated, wasAuthenticated) => {
        if (authenticated) {
            void connectors.load().catch(() => undefined);
        }

        if (authenticated && wasAuthenticated === false) {
            void preferences.ensureLoaded().catch(() => undefined);
        }
    },
    { immediate: true },
);

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

/** Header/footer microphone: mutes the voice session and the wake word (RF-06). */
function onMicrophone(): void {
    realtimeVoice.toggleMute();
}
</script>

<template>
    <HudLayout
        v-if="route.meta.requiresAuth"
        :voice-unavailable="!voice.available"
        :muted="voice.muted"
        @send="onSend"
        @sleep="realtimeVoice.sleep()"
        @wake="realtimeVoice.wake()"
        @microphone="onMicrophone"
    >
        <template #main>
            <RouterView />
        </template>
    </HudLayout>
    <RouterView v-else />
    <HudToast />
    <UpdatePrompt />
    <TaskDetail />
</template>
