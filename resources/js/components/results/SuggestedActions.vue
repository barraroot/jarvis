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
        <span class="hud-label font-mono text-[0.6rem] text-muted">{{ t('results.suggestedActions') }}:</span>
        <button
            v-for="(action, index) in visibleActions"
            :key="index"
            type="button"
            class="hud-label rounded-sm border border-cyan/60 bg-bg-navy px-2 py-1 text-[0.6rem] text-cyan-bright transition-colors hover:border-cyan hover:bg-cyan/10"
            :data-testid="`suggested-action-${index}`"
            @click="send(action)"
        >
            {{ action.label }}
        </button>
    </div>
</template>
