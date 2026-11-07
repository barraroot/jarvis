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

/** The `best` item moves to the top; the TransitionGroup animates the reorder (FLIP, UI-12). */
const orderedItems = computed<VisualizationResultItem[]>(() => [
    ...props.section.items.filter(isBest),
    ...props.section.items.filter((item) => !isBest(item)),
]);

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
            class="chamfer-sm relative flex flex-col gap-1.5 overflow-hidden border border-ocean-600/60 p-2"
            :class="{ 'viz-shimmer': !reducedMotion }"
            data-testid="viz-results-skeleton-row"
        >
            <span class="h-3 w-2/3 bg-ocean-600/70"></span>
            <span class="h-2 w-1/2 bg-ocean-600/50"></span>
        </div>
    </div>

    <p v-else-if="section.items.length === 0" class="font-mono text-xs text-text-400" data-testid="viz-results-empty">
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
            v-for="(item, index) in orderedItems"
            :key="itemKey(item)"
            class="chamfer-sm flex min-w-0 items-start gap-3 border p-2"
            :class="isBest(item) ? 'border-cyan-300/70 bg-cyan-300/10' : 'border-ocean-600/60'"
            data-testid="viz-result-item"
        >
            <span class="font-mono text-xs text-text-400 tabular-nums">{{ pad(index + 1) }}</span>
            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-sm text-text-100" data-testid="viz-result-title">{{ item.title }}</span>
                <span class="truncate font-mono text-[0.7rem] text-text-400" data-testid="viz-result-subtitle">{{ item.subtitle }}</span>
                <span v-if="item.badges.length > 0" class="mt-1 flex flex-wrap gap-1">
                    <span
                        v-if="isBest(item)"
                        class="label-caps chamfer-sm border border-cyan-300 bg-cyan-300/20 px-1.5 py-0.5 text-[0.6rem] text-biolum-200"
                        data-testid="viz-result-best"
                    >{{ t('visualizations.best') }}</span>
                    <span
                        v-for="badge in freeBadges(item)"
                        :key="badge"
                        class="label-caps chamfer-sm border border-ocean-600 px-1.5 py-0.5 text-[0.6rem] text-text-400"
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
        transform var(--dur-base) var(--ease-fly),
        opacity var(--dur-base) var(--ease-fly);
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

/* Skeleton shimmer in --ocean-400 while syncing (UI-12); transform only. */
.viz-shimmer::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, transparent, color-mix(in srgb, var(--color-ocean-400) 28%, transparent), transparent);
    transform: translateX(-100%);
    animation: viz-shimmer 1.4s ease-in-out infinite;
}

@keyframes viz-shimmer {
    to {
        transform: translateX(100%);
    }
}
</style>
