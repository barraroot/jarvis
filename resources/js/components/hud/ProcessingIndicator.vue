<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { AssistantState } from '@/types/models';

const props = defineProps<{
    state: AssistantState;
}>();

const { t } = useI18n();

/** Only the reasoning/executing phases show a processing row. */
const label = computed(() => (props.state === 'EXECUTING' ? t('status.EXECUTING') : t('status.THINKING')));
</script>

<template>
    <div
        class="glass chamfer-sm flex items-center gap-2 self-start border-ocean-400/60 px-3 py-1.5"
        role="status"
        data-testid="processing-indicator"
    >
        <span class="flex items-center gap-1" aria-hidden="true">
            <span class="size-1 animate-hud-pulse rounded-full bg-cyan-300" data-dot></span>
            <span class="size-1 animate-hud-pulse rounded-full bg-cyan-300 [animation-delay:0.2s]" data-dot></span>
            <span class="size-1 animate-hud-pulse rounded-full bg-cyan-300 [animation-delay:0.4s]" data-dot></span>
        </span>
        <span class="label-caps text-[0.65rem] text-cyan-300">{{ label }}…</span>
    </div>
</template>
