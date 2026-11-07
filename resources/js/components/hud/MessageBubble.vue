<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import MemoryHypothesesCard from '@/components/hud/MemoryHypothesesCard.vue';
import type { Message } from '@/types/models';

const props = defineProps<{
    message: Message;
}>();

const emit = defineEmits<{
    retry: [message: Message];
}>();

const { t } = useI18n();

const isUser = computed(() => props.message.role === 'user');
/** Assistant reply still receiving chunks: blinking caret at the end. */
const isStreaming = computed(() => !isUser.value && props.message.status === 'running');
const isFailed = computed(() => props.message.status === 'failed');

/** Letter avatar beside the bubble (`board-05-conversation.html`): first letter of the speaker label. */
const avatarLetter = computed(() => (isUser.value ? t('conversation.you') : t('conversation.jarvis')).charAt(0).toUpperCase());
</script>

<template>
    <article
        class="flex min-w-0 items-start gap-2"
        :class="isUser ? 'flex-row justify-end' : 'flex-row-reverse justify-end'"
        :aria-label="isUser ? t('conversation.you') : t('conversation.jarvis')"
        :data-testid="isUser ? 'message-bubble-user' : 'message-bubble-assistant'"
    >
        <div
            class="glass chamfer-md flex min-w-0 flex-col gap-1 border px-3 py-2 sm:max-w-[75%]"
            :class="[isUser ? 'border-ocean-600 bg-abyss-800/70 max-w-[85%]' : isFailed ? 'border-danger-400/60 max-w-full' : 'border-cyan-300/40 max-w-full']"
            data-testid="message-body"
        >
            <div class="flex min-w-0 items-start gap-2">
                <span
                    v-if="!isUser"
                    class="shrink-0 font-mono text-sm leading-5 text-cyan-300 glow"
                    aria-hidden="true"
                    data-testid="message-prefix"
                >
                    »
                </span>

                <p class="min-w-0 flex-1 whitespace-pre-wrap break-words text-sm text-text-100">
                    {{ message.content }}<span
                        v-if="isStreaming"
                        class="animate-hud-pulse text-biolum-200 glow"
                        :aria-label="t('conversation.streaming')"
                        data-testid="message-streaming-caret"
                    >▍</span>
                </p>

                <!-- Voice messages carry a waveform glyph; text messages a keyboard glyph. -->
                <svg
                    v-if="isUser"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    class="mt-0.5 size-3.5 shrink-0 text-text-400"
                    aria-hidden="true"
                    :data-testid="message.source === 'voice' ? 'message-source-voice' : 'message-source-text'"
                >
                    <template v-if="message.source === 'voice'">
                        <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                        <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                        <line x1="12" x2="12" y1="19" y2="22" />
                    </template>
                    <template v-else>
                        <rect x="2" y="4" width="20" height="16" rx="2" />
                        <path d="M6 8h.01M10 8h.01M14 8h.01M18 8h.01M6 12h.01M10 12h.01M14 12h.01M18 12h.01M8 16h8" />
                    </template>
                </svg>
            </div>

            <div v-if="isFailed" class="flex items-center gap-2" data-testid="message-failed">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    class="size-3.5 shrink-0 text-danger-400"
                    aria-hidden="true"
                    data-testid="message-error-icon"
                >
                    <path d="M12 9v4" />
                    <path d="M12 17h.01" />
                    <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
                </svg>
                <span class="label-caps font-mono text-[0.6rem] text-danger-400">{{ t('conversation.failed') }}</span>
                <button
                    type="button"
                    class="label-caps chamfer-sm ml-auto min-h-11 border border-danger-400/60 px-2 py-1 text-[0.6rem] text-danger-400 hover:bg-danger-400/15"
                    data-testid="message-retry"
                    @click="emit('retry', message)"
                >
                    {{ t('conversation.retry') }}
                </button>
            </div>

            <!-- Structured results (Phase 14) render in this slot. -->
            <slot name="payload" :message="message" />

            <!-- Hypothesis confirmation prompt (US-6.2) on assistant replies. -->
            <MemoryHypothesesCard v-if="!isUser" :message="message" />
        </div>

        <!-- After the body in the DOM so the bubble stays the first child; flex direction places it. -->
        <span
            class="chamfer-sm flex size-8 shrink-0 items-center justify-center border font-logo text-xs"
            :class="isUser ? 'border-ocean-600 bg-abyss-800 text-text-100' : 'border-cyan-300/60 bg-cyan-300/10 text-cyan-300 glow'"
            aria-hidden="true"
            data-testid="message-avatar"
        >
            {{ avatarLetter }}
        </span>
    </article>
</template>
