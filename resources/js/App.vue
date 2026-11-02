<script setup lang="ts">
import { onMounted, watch } from 'vue';
import { RouterView, useRoute, useRouter } from 'vue-router';
import HudToast from '@/components/ui/HudToast.vue';
import TaskDetail from '@/components/hud/TaskDetail.vue';
import { bindAgentEvents } from '@/composables/useAgentEvents';
import { bindApprovalEvents } from '@/composables/useApprovalEvents';
import { bindConnectorEvents } from '@/composables/useConnectorEvents';
import { bindNotificationEvents } from '@/composables/useNotificationEvents';
import { bindTaskEvents } from '@/composables/useTaskEvents';
import { useRealtimeConnection } from '@/composables/useRealtimeConnection';
import { useSpeechOutput } from '@/composables/useSpeechOutput';
import { bindToolEvents } from '@/composables/useToolEvents';
import { useVoiceInput } from '@/composables/useVoiceInput';
import { useWakeWord } from '@/composables/useWakeWord';
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

const voice = useVoiceInput();
const speech = useSpeechOutput();
const voicePartial = voice.partialText;
const voiceBlocked = voice.permissionDenied;
const speaking = speech.speaking;

/** "Hey JARVIS" starts push-to-talk without a click (US-4.4). */
const wakeWord = useWakeWord({ onWake: () => void voice.start() });
const wakeWordActive = wakeWord.listening;

useRealtimeConnection([
    (channel) => speech.bind(channel),
    bindToolEvents,
    bindAgentEvents,
    bindApprovalEvents,
    bindNotificationEvents,
    bindTaskEvents,
    bindConnectorEvents,
]);

onMounted(() => {
    void preferences.ensureLoaded().catch(() => undefined);
});

/** Header connector indicator (US-9.4): loaded once the operator is signed in. */
watch(
    () => auth.isAuthenticated,
    (authenticated) => {
        if (authenticated) {
            void connectors.load().catch(() => undefined);
        }
    },
    { immediate: true },
);

/** Footer send: from the HUD a fresh conversation is created first, then navigated to. */
async function onSend(payload: { content: string; source: 'text' | 'voice' }): Promise<void> {
    speech.stop();

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

/** Push-to-talk: the voice button, Tab or the header mic toggles capture (US-4.1). */
function onVoice(): void {
    void voice.toggle();
}

/** Stop button / Esc: cancel the spoken response and finalize the capture (US-4.3). */
function onInterrupt(): void {
    speech.stop();

    if (voice.listening.value) {
        void voice.finish();
    }
}

/** Header toggle "resposta falada" (US-4.2): persists and stops the current speech when off. */
function onToggleSpoken(): void {
    const next = !preferences.spokenResponsesEnabled;

    preferences.update({ spoken_responses_enabled: next }).catch(() => undefined);

    if (!next) {
        speech.stop();
    }
}
</script>

<template>
    <HudLayout
        v-if="route.meta.requiresAuth"
        :voice-partial="voicePartial"
        :voice-blocked="voiceBlocked"
        :speaking="speaking"
        :spoken-responses-enabled="preferences.spokenResponsesEnabled"
        :wake-word-active="wakeWordActive"
        @send="onSend"
        @voice="onVoice"
        @microphone="onVoice"
        @interrupt="onInterrupt"
        @toggle-spoken="onToggleSpoken"
        @pause-speech="speech.pause"
        @resume-speech="speech.resume"
        @stop-speech="speech.stop"
    >
        <template #main>
            <RouterView />
        </template>
    </HudLayout>
    <RouterView v-else />
    <HudToast />
    <TaskDetail />
</template>
