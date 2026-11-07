<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
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
import { useReducedMotion } from '@/composables/useReducedMotion';
import { useRealtimeConnection } from '@/composables/useRealtimeConnection';
import { useRealtimeVoice } from '@/composables/useRealtimeVoice';
import { bindToolEvents } from '@/composables/useToolEvents';
import { bindVisualizationEvents } from '@/composables/useVisualizationEvents';
import HudLayout from '@/layouts/HudLayout.vue';
import { HttpError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useConnectorsStore } from '@/stores/connectors';
import { useConversationsStore } from '@/stores/conversations';
import { useLimitsStore } from '@/stores/limits';
import { useMessagesStore } from '@/stores/messages';
import { usePreferencesStore } from '@/stores/preferences';

const route = useRoute();
const router = useRouter();
const conversations = useConversationsStore();
const messages = useMessagesStore();
const preferences = usePreferencesStore();
const connectors = useConnectorsStore();
const auth = useAuthStore();
const limits = useLimitsStore();
const reducedMotion = useReducedMotion();

/**
 * Route change (UI-24): the current screen sinks (scale .98 + blur) while the
 * next one emerges from below, both in `--dur-base` (350 ms). The leaving page
 * is taken out of the flow so the two overlap. Reduced motion swaps at once.
 */
const routeTransition = computed(() => ({
    css: !reducedMotion.value,
    enterActiveClass: 'transition-[opacity,transform] duration-[350ms] ease-(--ease-fly)',
    enterFromClass: 'opacity-0 translate-y-4',
    leaveActiveClass: 'pointer-events-none absolute inset-x-0 top-0 transition-[opacity,transform,filter] duration-[350ms] ease-in',
    leaveToClass: 'opacity-0 scale-[.98] blur-sm',
}));

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
    } catch (error) {
        // The stores already surfaced the failure through toasts; a 402 also raises the edge state (RF-09).
        if (error instanceof HttpError && error.status === 402) {
            limits.reportCostLimit(error.serverMessage);
        }
    }
}

/** Header/footer microphone: mutes the voice session and the wake word (RF-06). */
function onMicrophone(): void {
    realtimeVoice.toggleMute();
}

/** `Permitir microfone` (UI-22): re-asks the permission through the existing wake flow (`getUserMedia`). */
function onAllowMicrophone(): void {
    voice.markAvailable();
    void realtimeVoice.wake();
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
        @allow-microphone="onAllowMicrophone"
    >
        <template #main>
            <div class="relative min-w-0" :data-motion="reducedMotion ? 'reduced' : 'full'" data-testid="route-view">
                <RouterView v-slot="{ Component, route: viewRoute }">
                    <Transition v-bind="routeTransition">
                        <component :is="Component" :key="viewRoute.path" />
                    </Transition>
                </RouterView>
            </div>
        </template>
    </HudLayout>
    <RouterView v-else />
    <HudToast />
    <UpdatePrompt />
    <TaskDetail />
</template>
