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
        class="flex items-center gap-2 self-start rounded-sm border border-purple/50 bg-purple/10 px-3 py-1.5"
        role="status"
        data-testid="processing-indicator"
    >
        <span class="flex items-center gap-1" aria-hidden="true">
            <span class="size-1 animate-hud-pulse rounded-full bg-purple"></span>
            <span class="size-1 animate-hud-pulse rounded-full bg-purple [animation-delay:0.2s]"></span>
            <span class="size-1 animate-hud-pulse rounded-full bg-purple [animation-delay:0.4s]"></span>
        </span>
        <span class="hud-label font-mono text-[0.6rem] text-purple">{{ label }}…</span>
    </div>
</template>
