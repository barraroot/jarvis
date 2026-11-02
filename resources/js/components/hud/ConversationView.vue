<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import JarvisCore from '@/components/hud/JarvisCore.vue';
import ApprovalMessageCard from '@/components/hud/ApprovalMessageCard.vue';
import MessageBubble from '@/components/hud/MessageBubble.vue';
import ProcessingIndicator from '@/components/hud/ProcessingIndicator.vue';
import ResultRenderer from '@/components/results/ResultRenderer.vue';
import { useAssistantStore } from '@/stores/assistant';
import type { Message } from '@/types/models';

const props = withDefaults(
    defineProps<{
        /** Conversation messages in display order (oldest first), fed by the stores. */
        messages?: Message[];
        /** True when the server still has older messages behind the cursor. */
        hasOlder?: boolean;
    }>(),
    { messages: () => [], hasOlder: false },
);

const emit = defineEmits<{
    retry: [message: Message];
    loadOlder: [];
}>();

const { t } = useI18n();
const assistant = useAssistantStore();

const processing = computed(() => assistant.state === 'THINKING' || assistant.state === 'EXECUTING');

/** The bubble already receiving chunks replaces the processing row. */
const streamingMessage = computed(() =>
    props.messages.find((message) => message.role === 'assistant' && message.status === 'running'),
);

const showProcessing = computed(() => processing.value && streamingMessage.value === undefined);
const showGreeting = computed(() => props.messages.length === 0 && !processing.value);

const scrollerRef = ref<HTMLElement | null>(null);
const scrolledUp = ref(false);

function scrollToEnd(): void {
    const scroller = scrollerRef.value;

    if (scroller !== null) {
        scroller.scrollTop = scroller.scrollHeight;
        scrolledUp.value = false;
    }
}

function onScroll(): void {
    const scroller = scrollerRef.value;

    if (scroller === null) {
        return;
    }

    scrolledUp.value = scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight > 80;
}

/** New messages, streamed content and state changes keep the view pinned to the end. */
watch(
    () => [props.messages.length, props.messages.at(-1)?.content, processing.value] as const,
    async () => {
        await nextTick();
        scrollToEnd();
    },
);
</script>

<template>
    <div class="relative flex h-full min-h-0 flex-col" data-testid="conversation-view" :aria-label="t('conversation.ariaLabel')">
        <div ref="scrollerRef" class="min-h-0 flex-1 overflow-y-auto pb-2" data-testid="conversation-scroller" @scroll="onScroll">
            <div v-if="showGreeting" class="flex h-full flex-col items-center justify-center gap-8 py-10" data-testid="conversation-greeting">
                <p class="hud-label max-w-md text-center text-sm leading-relaxed text-cyan-bright hud-glow">
                    {{ t('conversation.greeting') }}
                </p>
                <p class="max-w-md text-center font-mono text-xs text-muted">
                    {{ t('conversation.greetingHint') }}
                </p>
                <JarvisCore :state="assistant.state" :level="assistant.audioLevel" size="full" class="hidden lg:block" />
            </div>

            <div v-else class="flex min-w-0 flex-col gap-3" data-testid="conversation-messages">
                <div v-if="hasOlder" class="flex justify-center" data-testid="conversation-load-older">
                    <button
                        type="button"
                        class="hud-label rounded-sm border border-line bg-bg-navy px-3 py-1.5 text-[0.6rem] text-cyan-bright hover:border-cyan"
                        @click="emit('loadOlder')"
                    >
                        {{ t('conversation.loadOlder') }}
                    </button>
                </div>

                <MessageBubble v-for="message in messages" :key="message.id" :message="message" @retry="emit('retry', $event)">
                    <template #payload="slotProps">
                        <ApprovalMessageCard
                            v-if="slotProps.message.payload?.type === 'approval_request'"
                            :message="slotProps.message"
                        />
                        <ResultRenderer v-else-if="slotProps.message.role === 'assistant'" :message="slotProps.message" />
                        <slot name="payload" v-bind="slotProps" />
                    </template>
                </MessageBubble>

                <ProcessingIndicator v-if="showProcessing" :state="assistant.state" />
            </div>
        </div>

        <button
            v-if="scrolledUp"
            type="button"
            class="hud-label absolute bottom-3 left-1/2 -translate-x-1/2 rounded-sm border border-line bg-bg-navy px-3 py-1.5 text-[0.6rem] text-cyan-bright hover:border-cyan"
            data-testid="conversation-go-to-end"
            @click="scrollToEnd"
        >
            {{ t('conversation.goToEnd') }}
        </button>
    </div>
</template>
