<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
    defineProps<{
        value: number;
        max?: number;
        label?: string;
        variant?: 'amber' | 'cyan';
        showValue?: boolean;
    }>(),
    { max: 100, label: undefined, variant: 'cyan', showValue: true },
);

const { t } = useI18n();

const percent = computed(() => {
    if (props.max <= 0 || !Number.isFinite(props.value)) {
        return 0;
    }

    return Math.round(Math.min(100, Math.max(0, (props.value / props.max) * 100)));
});
</script>

<template>
    <div class="flex min-w-0 items-center gap-3 font-mono text-xs">
        <span v-if="label" class="label-caps w-12 shrink-0 truncate text-[0.6rem] text-text-400">{{ label }}</span>
        <div
            class="h-2 min-w-0 flex-1 overflow-hidden bg-abyss-900"
            role="progressbar"
            :aria-valuenow="percent"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="label ?? t('progress.label')"
        >
            <div
                class="h-full transition-[width] duration-(--dur-base)"
                :class="variant === 'amber' ? 'bg-amber-400' : 'bg-cyan-300'"
                :style="{ width: `${percent}%` }"
                data-testid="progress-fill"
            ></div>
        </div>
        <span v-if="showValue" class="w-10 shrink-0 text-right text-text-100">{{ percent }}%</span>
    </div>
</template>
