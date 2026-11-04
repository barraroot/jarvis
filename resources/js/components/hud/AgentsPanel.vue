<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import TaskTimeline from '@/components/hud/TaskTimeline.vue';
import { STOPPABLE_STATUSES, TERMINAL_TASK_STATUSES, useTaskTimelineStore } from '@/stores/taskTimeline';
import { useTasksStore } from '@/stores/tasks';
import type { TaskSummary } from '@/types/models';

/** Counter of the panel header (UI-10, D4). */
type PanelCounter = { kind: 'active'; count: number } | { kind: 'completed' | 'failed' | 'cancelled' } | null;

const { t } = useI18n();
const tasksStore = useTasksStore();
const timeline = useTaskTimelineStore();

/** Ticks the `mm:ss` timers once per second. */
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    timer = setInterval(() => {
        now.value = Date.now();
    }, 1000);
});

onBeforeUnmount(() => {
    if (timer !== null) {
        clearInterval(timer);
    }
});

/** Active tasks (restored on load) plus every task seen finishing in this page (D4). */
const tasks = computed<TaskSummary[]>(() => {
    const byId = new Map<string, TaskSummary>();

    for (const task of Object.values(timeline.snapshots)) {
        byId.set(String(task.id), task);
    }

    for (const task of tasksStore.tasks) {
        const snapshot = byId.get(String(task.id));

        if (snapshot === undefined || !TERMINAL_TASK_STATUSES.has(snapshot.status)) {
            byId.set(String(task.id), task);
        }
    }

    return [...byId.values()].sort((left, right) => Number(left.id) - Number(right.id));
});

/** `<N> ATIVO`; otherwise failed > cancelled > completed (D4). */
const counter = computed<PanelCounter>(() => {
    if (tasks.value.length === 0) {
        return null;
    }

    const active = tasks.value.filter((task) => !TERMINAL_TASK_STATUSES.has(task.status)).length;

    if (active > 0) {
        return { kind: 'active', count: active };
    }

    if (tasks.value.some((task) => task.status === 'failed')) {
        return { kind: 'failed' };
    }

    if (tasks.value.some((task) => task.status === 'cancelled')) {
        return { kind: 'cancelled' };
    }

    return { kind: 'completed' };
});

const counterText = computed(() => {
    const value = counter.value;

    if (value === null) {
        return '';
    }

    return value.kind === 'active' ? t('agentsPanel.active', { n: value.count }) : t(`agentsPanel.${value.kind}`);
});

const counterClasses: Record<NonNullable<PanelCounter>['kind'], string> = {
    active: 'border-cyan/60 text-cyan-bright',
    completed: 'border-success/60 text-success',
    failed: 'border-danger/60 text-danger',
    cancelled: 'border-muted/60 text-muted',
};

function cardClasses(task: TaskSummary): string {
    switch (task.status) {
        case 'completed':
            return 'border-success/60';
        case 'failed':
            return 'border-danger/70';
        case 'cancelled':
            return 'border-muted/50 opacity-70';
        default:
            return 'border-cyan/50';
    }
}

function timestamp(value: string | null | undefined): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const parsed = Date.parse(value);

    return Number.isNaN(parsed) ? null : parsed;
}

/** `mm:ss` since the task started; frozen at completion (UI-10). */
function elapsed(task: TaskSummary): string {
    const start = timestamp(task.started_at) ?? timestamp(task.queued_at) ?? timestamp(task.created_at);

    if (start === null) {
        return '00:00';
    }

    const end = TERMINAL_TASK_STATUSES.has(task.status) ? (timestamp(task.completed_at) ?? now.value) : now.value;
    const seconds = Math.max(0, Math.floor((end - start) / 1000));
    const pad = (value: number): string => String(value).padStart(2, '0');

    return `${pad(Math.floor(seconds / 60))}:${pad(seconds % 60)}`;
}

function canStop(task: TaskSummary): boolean {
    return STOPPABLE_STATUSES.has(task.status);
}

function failureLabel(task: TaskSummary): string {
    return task.error !== null && task.error !== undefined && task.error !== '' ? task.error : t('agentsPanel.failedFallback');
}
</script>

<template>
    <section class="hud-glass flex min-h-0 flex-col gap-3 rounded-sm border border-line p-3" data-testid="agents-panel">
        <header class="flex items-center justify-between gap-2">
            <h2 class="hud-label font-display text-xs text-cyan">{{ t('agentsPanel.title') }}</h2>
            <span
                v-if="counter !== null"
                class="hud-label rounded-sm border px-1.5 py-0.5 font-mono text-[0.6rem]"
                :class="counterClasses[counter.kind]"
                :data-kind="counter.kind"
                data-testid="agents-counter"
            >
                {{ counterText }}
            </span>
        </header>

        <p v-if="tasks.length === 0" class="font-mono text-[0.7rem] text-muted" data-testid="agents-empty">
            {{ t('agentsPanel.empty') }}
        </p>

        <ul v-else class="flex min-h-0 flex-col gap-3 overflow-y-auto">
            <li
                v-for="task in tasks"
                :key="task.id"
                class="flex flex-col gap-2 rounded-sm border bg-bg/40 p-2.5"
                :class="cardClasses(task)"
                :data-status="task.status"
                :data-testid="`agents-card-${task.id}`"
            >
                <div class="flex items-center gap-2">
                    <span class="hud-label font-mono text-[0.6rem] text-cyan">{{ t('agentsPanel.task') }}</span>
                    <span v-if="task.model" class="min-w-0 truncate font-mono text-[0.6rem] text-muted" data-testid="agents-card-model">
                        {{ task.model.name }}
                    </span>
                    <time
                        class="ml-auto font-mono text-[0.65rem] tabular-nums text-text"
                        :aria-label="t('agentsPanel.elapsed')"
                        data-testid="agents-card-timer"
                    >
                        {{ elapsed(task) }}
                    </time>
                    <button
                        v-if="canStop(task)"
                        type="button"
                        class="hud-label inline-flex min-h-11 items-center rounded-sm border border-danger/70 px-2 text-[0.6rem] text-danger hover:bg-danger/15 disabled:opacity-50 xl:min-h-0 xl:py-0.5"
                        :disabled="timeline.stopping[String(task.id)] === true"
                        data-testid="agents-card-stop"
                        @click="timeline.stop(task.id)"
                    >
                        {{ timeline.stopping[String(task.id)] === true ? t('agentsPanel.stopping') : t('agentsPanel.stop') }}
                    </button>
                    <span
                        v-else
                        class="hud-label font-mono text-[0.6rem]"
                        :class="{
                            'text-success': task.status === 'completed',
                            'text-danger': task.status === 'failed',
                            'text-muted': task.status === 'cancelled',
                        }"
                        data-testid="agents-card-status"
                    >
                        {{ t(`status.${task.status}`) }}
                    </span>
                </div>

                <p class="line-clamp-2 text-xs text-text" :title="task.title" data-testid="agents-card-description">{{ task.title }}</p>

                <p
                    v-if="timeline.stopErrors[String(task.id)]"
                    class="text-[0.7rem] text-danger"
                    role="alert"
                    data-testid="agents-card-stop-error"
                >
                    {{ timeline.stopErrors[String(task.id)] }}
                </p>

                <p v-if="task.status === 'failed'" class="text-[0.7rem] text-danger" data-testid="agents-card-error">
                    {{ failureLabel(task) }}
                </p>

                <div
                    v-if="timeline.currentItem(task.id) !== null && !TERMINAL_TASK_STATUSES.has(task.status)"
                    class="flex min-w-0 flex-col rounded-sm border border-cyan/40 bg-cyan/10 px-2 py-1"
                    :aria-label="t('agentsPanel.currentItem')"
                    data-testid="agents-card-current"
                >
                    <span class="truncate font-mono text-[0.7rem] text-cyan-bright">{{ timeline.currentItem(task.id)?.text }}</span>
                    <span v-if="timeline.currentItem(task.id)?.detail" class="truncate font-mono text-[0.6rem] text-muted">
                        {{ timeline.currentItem(task.id)?.detail }}
                    </span>
                </div>

                <TaskTimeline v-if="timeline.entriesFor(task.id).length > 0" :entries="timeline.entriesFor(task.id)" />
            </li>
        </ul>
    </section>
</template>
