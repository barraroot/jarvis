<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useMessagesStore } from '@/stores/messages';
import type { SuggestedAction } from '@/types/results';

const props = defineProps<{
    actions: SuggestedAction[];
}>();

const { t } = useI18n();
const messages = useMessagesStore();

/** The backend caps at four; slice defensively for payloads that slip through. */
const visibleActions = props.actions.slice(0, 4);

async function send(action: SuggestedAction): Promise<void> {
    try {
        await messages.send(action.request, 'text');
    } catch {
        // Sending failed (offline, no conversation): the store already toasts.
    }
}
</script>

<template>
    <div v-if="visibleActions.length > 0" class="flex flex-wrap items-center gap-1.5" data-testid="suggested-actions">
        <span class="label-caps font-mono text-[0.6rem] text-text-400">{{ t('results.suggestedActions') }}:</span>
        <button
            v-for="(action, index) in visibleActions"
            :key="index"
            type="button"
            class="label-caps chamfer-sm corner-light min-h-11 border border-cyan-300/60 bg-abyss-900 px-3 py-1 text-[0.6rem] text-biolum-200 transition-colors hover:border-cyan-300 hover:bg-cyan-300/10"
            :data-testid="`suggested-action-${index}`"
            @click="send(action)"
        >
            {{ action.label }}
        </button>
    </div>
</template>
