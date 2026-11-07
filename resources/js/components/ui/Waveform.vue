<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
    defineProps<{
        /** Input level between 0 and 1 (e.g. microphone RMS). */
        level: number;
        bars?: number;
    }>(),
    { bars: 24 },
);

const { t } = useI18n();

const clampedLevel = computed(() => (Number.isFinite(props.level) ? Math.min(1, Math.max(0, props.level)) : 0));

/** Symmetric envelope: center bars are tallest; height scales with the level. */
const heights = computed(() =>
    Array.from({ length: props.bars }, (_, index) => {
        const center = (props.bars - 1) / 2;
        const distance = center === 0 ? 0 : Math.abs(index - center) / center;
        const envelope = 1 - distance * 0.7;

        return Math.round(Math.max(0.08, clampedLevel.value * envelope) * 100);
    }),
);
</script>

<template>
    <div
        class="flex h-10 w-full max-w-full items-center justify-center gap-[2px] overflow-hidden"
        role="meter"
        :aria-label="t('waveform.label')"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="Math.round(clampedLevel * 100)"
    >
        <span
            v-for="(height, index) in heights"
            :key="index"
            class="w-[3px] shrink bg-cyan-300 transition-[height] duration-(--dur-fast)"
            :style="{ height: `${height}%`, opacity: 0.4 + clampedLevel * 0.6 }"
            data-testid="waveform-bar"
        ></span>
    </div>
</template>
