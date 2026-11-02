<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Message } from '@/types/models';

const props = defineProps<{
    message: Message;
}>();

const { t } = useI18n();

const isUser = computed(() => props.message.role === 'user');
</script>

<template>
    <article
        class="flex min-w-0"
        :class="isUser ? 'justify-end' : 'justify-start'"
        :aria-label="isUser ? t('conversation.you') : t('conversation.jarvis')"
        :data-testid="isUser ? 'message-bubble-user' : 'message-bubble-assistant'"
    >
        <div
            class="hud-glass flex max-w-[85%] min-w-0 flex-col gap-1 rounded-sm border px-3 py-2 sm:max-w-[75%]"
            :class="isUser ? 'border-line' : 'border-cyan/40'"
        >
            <div class="flex min-w-0 items-start gap-2">
                <span
                    v-if="!isUser"
                    class="shrink-0 font-mono text-sm leading-5 text-cyan hud-glow"
                    aria-hidden="true"
                    data-testid="message-prefix"
                >
                    »
                </span>

                <p class="min-w-0 flex-1 whitespace-pre-wrap break-words font-mono text-sm text-text">
                    {{ message.content }}
                </p>

                <!-- Voice messages carry a waveform glyph; text messages a keyboard glyph. -->
                <svg
                    v-if="isUser"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    class="mt-0.5 size-3.5 shrink-0 text-muted"
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

            <!-- Structured results (Phase 14) render in this slot. -->
            <slot name="payload" :message="message" />
        </div>
    </article>
</template>
