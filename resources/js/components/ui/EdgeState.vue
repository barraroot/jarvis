<script setup lang="ts">
import { computed } from 'vue';

export type EdgeStateTone = 'offline' | 'cost-limit' | 'voice' | 'error';

/**
 * Edge state card (UI-22, `board-10-edge-states.html`): abyss glass with the
 * medium chamfer and the status color of its tone — offline and errors in
 * danger, monthly cost limit and voice unavailable in amber.
 */
const props = withDefaults(
    defineProps<{
        tone: EdgeStateTone;
        title: string;
        text?: string;
    }>(),
    { text: undefined },
);

const toneClasses: Record<EdgeStateTone, { card: string; title: string }> = {
    offline: { card: 'border-l-danger-400', title: 'text-danger-400' },
    'cost-limit': { card: 'border-l-amber-400', title: 'text-amber-400' },
    voice: { card: 'border-l-amber-400', title: 'text-amber-400' },
    error: { card: 'border-l-danger-400', title: 'text-danger-400' },
};

/** Errors interrupt (`alert`); the other tones report a lasting condition (`status`). */
const role = computed(() => (props.tone === 'error' ? 'alert' : 'status'));
</script>

<template>
    <div
        class="glass chamfer-md flex min-w-0 flex-col gap-2 border-l-2 px-4 py-3"
        :class="[`edge-state--${tone}`, toneClasses[tone].card]"
        :role="role"
        :data-tone="tone"
        data-testid="edge-state"
    >
        <h3 class="label-caps text-sm" :class="toneClasses[tone].title" data-testid="edge-state-title">{{ title }}</h3>
        <p v-if="text" class="text-sm text-text-100" data-testid="edge-state-text">{{ text }}</p>
        <slot />
        <div v-if="$slots.actions" class="mt-1 flex flex-wrap gap-2" data-testid="edge-state-actions">
            <slot name="actions" />
        </div>
    </div>
</template>
