<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import HudButton from '@/components/ui/HudButton.vue';
import { useConversationsStore } from '@/stores/conversations';
import { useMemoriesStore } from '@/stores/memories';
import { useToastStore } from '@/stores/toast';
import type { Message, MemoryHypothesis } from '@/types/models';

const props = defineProps<{
    message: Message;
}>();

const { t } = useI18n();
const memories = useMemoriesStore();
const conversations = useConversationsStore();
const toasts = useToastStore();

const pendingId = ref<string | null>(null);

/** Replace the hypothesis list on the message after a decision (US-6.2). */
function applyHypotheses(next: MemoryHypothesis[]): void {
    conversations.replaceMessage({ ...props.message, hypotheses: next.length > 0 ? next : null });
}

async function confirm(hypothesis: MemoryHypothesis): Promise<void> {
    pendingId.value = hypothesis.id;

    try {
        await memories.confirm(hypothesis.id);
        applyHypotheses((props.message.hypotheses ?? []).filter((item) => item.id !== hypothesis.id));
        toasts.push(t('memories.confirmed'), { variant: 'success' });
    } catch {
        toasts.push(t('memories.actionError'), { variant: 'danger' });
    } finally {
        pendingId.value = null;
    }
}

async function discard(hypothesis: MemoryHypothesis): Promise<void> {
    pendingId.value = hypothesis.id;

    try {
        await memories.discard(hypothesis.id);
        applyHypotheses((props.message.hypotheses ?? []).filter((item) => item.id !== hypothesis.id));
        toasts.push(t('memories.discarded'), { variant: 'success' });
    } catch {
        toasts.push(t('memories.actionError'), { variant: 'danger' });
    } finally {
        pendingId.value = null;
    }
}
</script>

<template>
    <div
        v-if="(message.hypotheses ?? []).length > 0"
        class="flex min-w-0 flex-col gap-2 rounded-sm border border-purple/40 bg-purple/5 p-2"
        data-testid="memory-hypotheses-card"
    >
        <p class="hud-label text-[0.65rem] text-purple" data-testid="memory-hypotheses-title">
            {{ t('memoryCard.title') }}
        </p>

        <ul class="flex min-w-0 flex-col gap-2">
            <li
                v-for="hypothesis in message.hypotheses ?? []"
                :key="hypothesis.id"
                class="flex min-w-0 flex-col gap-1.5 border-b border-line/50 pb-2 last:border-none last:pb-0"
                data-testid="memory-hypothesis"
            >
                <p class="min-w-0 whitespace-pre-wrap break-words font-mono text-xs text-text">
                    {{ hypothesis.content }}
                </p>

                <div v-if="hypothesis.status === 'hypothesis'" class="flex items-center gap-2">
                    <HudButton
                        :disabled="pendingId === hypothesis.id"
                        data-testid="memory-hypothesis-confirm"
                        @click="confirm(hypothesis)"
                    >
                        {{ t('memoryCard.confirm') }}
                    </HudButton>
                    <HudButton
                        variant="danger"
                        :disabled="pendingId === hypothesis.id"
                        data-testid="memory-hypothesis-discard"
                        @click="discard(hypothesis)"
                    >
                        {{ t('memoryCard.discard') }}
                    </HudButton>
                </div>
            </li>
        </ul>
    </div>
</template>
