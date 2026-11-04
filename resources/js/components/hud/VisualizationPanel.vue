<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ScanBar from '@/components/hud/ScanBar.vue';
import VisualizationQuestion from '@/components/hud/VisualizationQuestion.vue';
import VisualizationResults from '@/components/hud/VisualizationResults.vue';
import VisualizationText from '@/components/hud/VisualizationText.vue';
import { VISUALIZATION_SCAN_MS } from '@/components/hud/constants';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { useVisualizationsStore } from '@/stores/visualizations';
import type { VisualizationSection } from '@/types/models';

const { t } = useI18n();
const visualizations = useVisualizationsStore();
const reducedMotion = useReducedMotion();

const visualization = computed(() => visualizations.current);

/** Sections in stable numeric order (RF-17). */
const sections = computed<VisualizationSection[]>(() =>
    [...(visualization.value?.sections ?? [])].sort((left, right) => left.position - right.position),
);

/**
 * Sections present when the panel (or a new visualization) shows up appear
 * at once; every section created afterwards is preceded by the scan bar and
 * typed in (UI-12). Reduced motion reveals immediately (RNF-06).
 */
const revealed = ref<Set<string>>(new Set(sections.value.map((section) => section.key)));
const animated = ref<Set<string>>(new Set());
const queue = ref<string[]>([]);
const scanningKey = ref<string | null>(null);
let scanTimer: ReturnType<typeof setTimeout> | null = null;

function reveal(key: string): void {
    revealed.value = new Set([...revealed.value, key]);
}

function stopScan(): void {
    if (scanTimer !== null) {
        clearTimeout(scanTimer);
        scanTimer = null;
    }
}

function processQueue(): void {
    if (scanningKey.value !== null) {
        return;
    }

    const next = queue.value[0];

    if (next === undefined) {
        return;
    }

    queue.value = queue.value.slice(1);

    if (reducedMotion.value) {
        reveal(next);
        processQueue();

        return;
    }

    scanningKey.value = next;
    scanTimer = setTimeout(() => {
        scanTimer = null;
        scanningKey.value = null;
        animated.value = new Set([...animated.value, next]);
        reveal(next);
        processQueue();
    }, VISUALIZATION_SCAN_MS);
}

watch(
    () => visualization.value?.id ?? null,
    (id, previousId) => {
        if (id === previousId) {
            return;
        }

        stopScan();
        scanningKey.value = null;
        queue.value = [];
        animated.value = new Set();
        revealed.value = new Set(sections.value.map((section) => section.key));
    },
);

watch(
    () => sections.value.map((section) => section.key),
    (keys) => {
        const fresh = keys.filter((key) => !revealed.value.has(key) && !queue.value.includes(key) && key !== scanningKey.value);

        if (fresh.length === 0) {
            return;
        }

        queue.value = [...queue.value, ...fresh];
        processQueue();
    },
);

watch(reducedMotion, (reduced) => {
    if (reduced) {
        stopScan();
        [...queue.value, ...(scanningKey.value === null ? [] : [scanningKey.value])].forEach(reveal);
        queue.value = [];
        scanningKey.value = null;
    }
});

onBeforeUnmount(stopScan);

const pad = (value: number): string => String(value).padStart(2, '0');

function clockOf(value: string | null | undefined): string {
    if (value === null || value === undefined) {
        return '--:--';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '--:--' : `${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** `SINCRONIZANDO · <fonte>` / `ATUALIZADO hh:mm` / `FALHOU` (UI-12). */
const statusText = computed(() => {
    const current = visualization.value;

    if (current === null) {
        return '';
    }

    if (current.status === 'syncing') {
        return current.source !== null && current.source !== ''
            ? t('visualizations.syncing', { source: current.source })
            : t('visualizations.syncingNoSource');
    }

    return current.status === 'updated' ? t('visualizations.updated', { time: clockOf(current.updated_at) }) : t('visualizations.failed');
});

const statusClasses = computed(() => {
    switch (visualization.value?.status) {
        case 'syncing':
            return 'text-amber';
        case 'failed':
            return 'text-danger';
        default:
            return 'text-success';
    }
});

/** `×` closes the panel (CT-09) without touching the task (RF-19a). */
function close(): void {
    void visualizations.close();
}
</script>

<template>
    <section
        v-if="visualization !== null"
        class="hud-glass flex min-h-0 flex-col gap-4 rounded-sm border border-line p-4"
        :aria-label="visualization.title"
        :data-status="visualization.status"
        data-testid="visualization-panel"
    >
        <header class="flex items-start gap-3">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mt-0.5 size-5 shrink-0 text-cyan" aria-hidden="true">
                <rect x="3" y="3" width="18" height="18" rx="2" />
                <path d="M3 9h18" /><path d="M9 21V9" />
            </svg>
            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="hud-label font-mono text-[0.6rem] text-cyan" data-testid="viz-label">
                    {{ t('visualizations.label', { time: clockOf(visualization.created_at) }) }}
                </span>
                <h2 class="truncate font-display text-sm text-text" data-testid="viz-title">{{ visualization.title }}</h2>
                <span v-if="visualization.source" class="truncate font-mono text-[0.65rem] text-muted" data-testid="viz-source">
                    {{ visualization.source }}
                </span>
                <span class="hud-label font-mono text-[0.6rem]" :class="statusClasses" data-testid="viz-status">{{ statusText }}</span>
            </div>
            <button
                type="button"
                class="inline-flex size-11 shrink-0 items-center justify-center rounded-sm border border-line text-muted hover:border-cyan hover:text-cyan-bright xl:size-8"
                :aria-label="t('visualizations.close')"
                data-testid="viz-close"
                @click="close"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </header>

        <div class="flex min-h-0 flex-col gap-5 overflow-y-auto">
            <template v-for="section in sections" :key="section.key">
                <ScanBar v-if="scanningKey === section.key" />
                <section
                    v-else-if="revealed.has(section.key)"
                    class="flex flex-col gap-2"
                    :data-type="section.type"
                    :data-testid="`viz-section-${section.key}`"
                >
                    <h3 class="hud-label flex items-baseline gap-2 font-mono text-[0.65rem] text-muted" data-testid="viz-section-heading">
                        <span class="text-cyan">{{ pad(section.position) }}</span>{{ ' ' }}<span>{{ section.label }}</span>
                    </h3>
                    <VisualizationQuestion v-if="section.type === 'question'" :section="section" />
                    <VisualizationResults v-else-if="section.type === 'results'" :section="section" />
                    <VisualizationText v-else-if="section.type === 'text'" :section="section" :animate="animated.has(section.key)" />
                </section>
            </template>
        </div>
    </section>
</template>
