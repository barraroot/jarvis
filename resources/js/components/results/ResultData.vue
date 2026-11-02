<script setup lang="ts">
import { computed } from 'vue';
import HudPanel from '@/components/ui/HudPanel.vue';
import type { DataResultPayload } from '@/types/results';

const props = defineProps<{
    payload: DataResultPayload;
}>();

/** Bar heights scaled to the series range (all bars 0 → flat minimum). */
const bars = computed(() => {
    const values = props.payload.series?.values ?? [];

    if (values.length === 0) {
        return [];
    }

    const max = Math.max(...values.map(Math.abs));
    const scale = max > 0 ? max : 1;

    return values.map((value) => Math.max(8, Math.round((Math.abs(value) / scale) * 100)));
});
</script>

<template>
    <HudPanel :title="payload.label" tag="div" data-testid="result-data">
        <p class="flex min-w-0 items-baseline gap-1 font-mono">
            <span class="truncate text-2xl text-cyan-bright hud-glow" data-testid="result-data-value">{{ payload.value }}</span>
            <span v-if="payload.unit" class="text-xs text-muted">{{ payload.unit }}</span>
        </p>

        <!-- Optional mini-series: proportional bars, no axes. -->
        <div
            v-if="payload.series && payload.series.values.length > 0"
            class="mt-2 flex min-w-0 flex-col gap-1"
            data-testid="result-data-series"
        >
            <span v-if="payload.series.label" class="hud-label text-[0.6rem] text-muted">{{ payload.series.label }}</span>
            <div class="flex h-10 items-end gap-0.5">
                <div
                    v-for="(value, index) in payload.series.values"
                    :key="index"
                    class="min-w-1 flex-1 rounded-sm bg-cyan/50"
                    :style="{ height: `${bars[index]}%` }"
                    :title="String(value)"
                />
            </div>
        </div>
    </HudPanel>
</template>
