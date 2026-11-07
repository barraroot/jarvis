<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ScanBar from '@/components/hud/ScanBar.vue';
import VisualizationQuestion from '@/components/hud/VisualizationQuestion.vue';
import VisualizationResults from '@/components/hud/VisualizationResults.vue';
import VisualizationText from '@/components/hud/VisualizationText.vue';
import { VISUALIZATION_SCAN_MS } from '@/components/hud/constants';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { useTaskTimelineStore } from '@/stores/taskTimeline';
import { useTasksStore } from '@/stores/tasks';
import { useVisualizationsStore } from '@/stores/visualizations';
import type { VisualizationSection } from '@/types/models';

const { t } = useI18n();
const visualizations = useVisualizationsStore();
const tasks = useTasksStore();
const timeline = useTaskTimelineStore();
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
            return 'text-amber-400';
        case 'failed':
            return 'text-danger-400';
        default:
            return 'text-success-400';
    }
});

const isFailed = computed(() => visualization.value?.status === 'failed');

/** Linked task (`task_id`) id when its last snapshot is `failed`: only then `Tentar de novo` shows (RF-07). */
const failedTaskId = computed<string | null>(() => {
    const taskId = visualization.value?.task_id;

    if (taskId === null || taskId === undefined) {
        return null;
    }

    return timeline.snapshots[String(taskId)]?.status === 'failed' ? String(taskId) : null;
});

function retry(): void {
    if (failedTaskId.value === null || tasks.acting) {
        return;
    }

    void tasks.retry(failedTaskId.value);
}

/** `×` closes the panel (CT-09) without touching the task (RF-19a). */
function close(): void {
    void visualizations.close();
}
</script>

<template>
    <section
        v-if="visualization !== null"
        class="glass chamfer-md flex min-h-0 flex-col gap-4 p-4"
        :class="{ 'viz--failed border-danger-400': isFailed }"
        :aria-label="visualization.title"
        :data-status="visualization.status"
        data-testid="visualization-panel"
    >
        <header class="flex items-start gap-3">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mt-0.5 size-5 shrink-0 text-cyan-300" aria-hidden="true">
                <path d="M3 7l4-4h10l4 4v10l-4 4H7l-4-4z" />
                <path d="M3 9h18" /><path d="M9 21V9" />
            </svg>
            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <span class="label-caps font-mono text-label text-cyan-300" data-testid="viz-label">
                    {{ t('visualizations.label', { time: clockOf(visualization.created_at) }) }}
                </span>
                <h2 class="truncate text-[1.05rem] font-medium text-text-100" data-testid="viz-title">{{ visualization.title }}</h2>
                <span v-if="visualization.source" class="truncate font-mono text-[0.7rem] text-text-400" data-testid="viz-source">
                    {{ visualization.source }}
                </span>
                <span class="label-caps inline-flex items-center gap-2 font-mono text-label" :class="statusClasses" data-testid="viz-status">
                    <span
                        v-if="visualization.status === 'syncing'"
                        class="viz-sonar relative inline-flex size-2 shrink-0"
                        :class="{ 'viz-sonar--animated': !reducedMotion }"
                        data-dot
                        aria-hidden="true"
                        data-testid="viz-sonar"
                    ></span>
                    {{ statusText }}
                </span>
            </div>
            <button
                type="button"
                class="chamfer-sm inline-flex size-11 shrink-0 items-center justify-center border border-ocean-600 text-text-400 hover:border-cyan-300 hover:text-biolum-200"
                :aria-label="t('visualizations.close')"
                data-testid="viz-close"
                @click="close"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </header>

        <div
            v-if="isFailed"
            class="chamfer-sm flex flex-wrap items-center gap-3 border border-danger-400/60 bg-danger-400/10 px-3 py-2"
            role="alert"
            data-testid="viz-failed"
        >
            <p class="min-w-0 flex-1 text-[0.85rem] text-danger-400" data-testid="viz-failed-message">{{ t('visualizations.failedMessage') }}</p>
            <button
                v-if="failedTaskId !== null"
                type="button"
                class="label-caps chamfer-sm inline-flex min-h-11 items-center border border-danger-400/70 px-3 text-[0.7rem] text-danger-400 hover:bg-danger-400/15 disabled:opacity-50"
                :disabled="tasks.acting"
                data-testid="viz-retry"
                @click="retry"
            >
                {{ t('edge.taskFailed.retry') }}
            </button>
        </div>

        <div class="flex min-h-0 flex-col gap-5 overflow-y-auto">
            <template v-for="section in sections" :key="section.key">
                <ScanBar v-if="scanningKey === section.key" />
                <section
                    v-else-if="revealed.has(section.key)"
                    class="flex flex-col gap-2"
                    :data-type="section.type"
                    :data-testid="`viz-section-${section.key}`"
                >
                    <h3 class="label-caps flex items-baseline gap-2 font-mono text-label text-text-400" data-testid="viz-section-heading">
                        <span class="text-cyan-300">{{ pad(section.position) }}</span>{{ ' ' }}<span>{{ section.label }}</span>
                    </h3>
                    <VisualizationQuestion v-if="section.type === 'question'" :section="section" />
                    <VisualizationResults v-else-if="section.type === 'results'" :section="section" />
                    <VisualizationText v-else-if="section.type === 'text'" :section="section" :animate="animated.has(section.key)" />
                </section>
            </template>
        </div>
    </section>
</template>

<style scoped>
/* Unlayered, so it wins over the glass border of the utilities layer (UI-12). */
.viz--failed {
    border-color: var(--color-danger-400);
}

.viz-sonar {
    border-radius: 50%;
    background-color: currentColor;
}

/* Sonar ping while syncing: one ring scaling out, transform/opacity only. */
.viz-sonar--animated::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 50%;
    border: 1px solid currentColor;
    animation: viz-sonar 1.6s var(--ease-sonar) infinite;
}

@keyframes viz-sonar {
    from {
        transform: scale(1);
        opacity: 0.9;
    }

    to {
        transform: scale(3);
        opacity: 0;
    }
}
</style>
