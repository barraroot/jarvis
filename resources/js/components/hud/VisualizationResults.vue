<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useReducedMotion } from '@/composables/useReducedMotion';
import type { VisualizationResultItem, VisualizationResultsSection } from '@/types/models';

/** Reserved badge rendered as `MELHOR RESULTADO` (CT-05). */
const BEST_BADGE = 'best';
const SKELETON_ROWS = 3;

const props = defineProps<{
    section: VisualizationResultsSection;
}>();

const { t } = useI18n();
const reducedMotion = useReducedMotion();

/** Skeleton while syncing without items; the list replaces it (UI-12). */
const showSkeleton = computed(() => props.section.status === 'syncing' && props.section.items.length === 0);

function itemKey(item: VisualizationResultItem): string {
    return `${item.title}\u0000${item.subtitle}`;
}

function isBest(item: VisualizationResultItem): boolean {
    return item.badges.includes(BEST_BADGE);
}

function freeBadges(item: VisualizationResultItem): string[] {
    return item.badges.filter((badge) => badge !== BEST_BADGE);
}

const pad = (value: number): string => String(value).padStart(2, '0');
</script>

<template>
    <div
        v-if="showSkeleton"
        class="flex flex-col gap-2"
        :aria-label="t('visualizations.loading')"
        aria-busy="true"
        data-testid="viz-results-skeleton"
    >
        <div
            v-for="row in SKELETON_ROWS"
            :key="row"
            class="flex flex-col gap-1.5 rounded-sm border border-line/60 p-2"
            :class="{ 'animate-pulse': !reducedMotion }"
        >
            <span class="h-3 w-2/3 rounded-sm bg-line/70"></span>
            <span class="h-2 w-1/2 rounded-sm bg-line/50"></span>
        </div>
    </div>

    <p v-else-if="section.items.length === 0" class="font-mono text-xs text-muted" data-testid="viz-results-empty">
        {{ t('visualizations.noResults') }}
    </p>

    <TransitionGroup
        v-else
        tag="ol"
        name="viz-results"
        :css="!reducedMotion"
        class="relative flex flex-col gap-2"
        data-testid="viz-results-list"
    >
        <li
            v-for="(item, index) in section.items"
            :key="itemKey(item)"
            class="flex min-w-0 items-start gap-3 rounded-sm border p-2"
            :class="isBest(item) ? 'border-cyan/70 bg-cyan/10' : 'border-line/60'"
            data-testid="viz-result-item"
        >
            <span class="font-mono text-xs text-muted tabular-nums">{{ pad(index + 1) }}</span>
            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-sm text-text" data-testid="viz-result-title">{{ item.title }}</span>
                <span class="truncate font-mono text-[0.65rem] text-muted" data-testid="viz-result-subtitle">{{ item.subtitle }}</span>
                <span v-if="item.badges.length > 0" class="mt-1 flex flex-wrap gap-1">
                    <span
                        v-if="isBest(item)"
                        class="hud-label rounded-sm border border-cyan bg-cyan/20 px-1 text-[0.55rem] text-cyan-bright"
                        data-testid="viz-result-best"
                    >{{ t('visualizations.best') }}</span>
                    <span
                        v-for="badge in freeBadges(item)"
                        :key="badge"
                        class="hud-label rounded-sm border border-line px-1 text-[0.55rem] text-muted"
                        data-testid="viz-result-badge"
                    >{{ badge }}</span>
                </span>
            </span>
        </li>
    </TransitionGroup>
</template>

<style scoped>
.viz-results-move,
.viz-results-enter-active,
.viz-results-leave-active {
    transition:
        transform 0.45s ease,
        opacity 0.45s ease;
}

.viz-results-enter-from,
.viz-results-leave-to {
    opacity: 0;
    transform: translateY(6px);
}

.viz-results-leave-active {
    position: absolute;
    inset-inline: 0;
}
</style>
