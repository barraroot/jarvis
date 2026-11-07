<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import TaskTimeline from '@/components/hud/TaskTimeline.vue';
import { STOPPABLE_STATUSES, TERMINAL_TASK_STATUSES, useTaskTimelineStore } from '@/stores/taskTimeline';
import { useTasksStore } from '@/stores/tasks';
import { useReducedMotion } from '@/composables/useReducedMotion';
import type { TaskStatus, TaskSummary } from '@/types/models';

/** Counter of the panel header (UI-10, D4). */
type PanelCounter = { kind: 'active'; count: number } | { kind: 'completed' | 'failed' | 'cancelled' } | null;

const { t } = useI18n();
const tasksStore = useTasksStore();
const timeline = useTaskTimelineStore();
const reducedMotion = useReducedMotion();

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

    if (value.kind === 'active') {
        return t('agents.counter.active', { n: value.count }, value.count);
    }

    return value.kind === 'completed' ? t('agents.counter.completed') : t(`agentsPanel.${value.kind}`);
});

const counterClasses: Record<NonNullable<PanelCounter>['kind'], string> = {
    active: 'border-cyan-300/60 text-cyan-300',
    completed: 'border-success-400/60 text-success-400',
    failed: 'border-danger-400/60 text-danger-400',
    cancelled: 'border-text-400/60 text-text-400',
};

/** One color class per status (UI-11); the marker class keeps pending/queued distinct. */
const STATUS_CLASSES: Record<TaskStatus, string> = {
    pending: 'agents-status--pending text-text-400 border-text-400/50',
    queued: 'agents-status--queued text-text-400 border-text-400/50',
    running: 'agents-status--running text-cyan-300 border-cyan-300/60',
    waiting_approval: 'agents-status--waiting_approval text-amber-400 border-amber-400/70',
    completed: 'agents-status--completed text-success-400 border-success-400/60',
    failed: 'agents-status--failed text-danger-400 border-danger-400/70',
    cancelled: 'agents-status--cancelled text-[#8a9aa8] border-[#8a9aa8]/50',
};

function statusClasses(task: TaskSummary): string {
    return STATUS_CLASSES[task.status] ?? STATUS_CLASSES.pending;
}

/** `model.name · agent.name`, whichever are known (UI-11). */
function modelAndAgent(task: TaskSummary): string {
    return [task.model?.name, task.agent?.name].filter((part): part is string => typeof part === 'string' && part !== '').join(' · ');
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

/** `conector · tool` of the last tool the failed task ran, when known (UI-21). */
function failedTool(task: TaskSummary): string | null {
    const lastTool = [...timeline.entriesFor(task.id)].reverse().find((entry) => entry.kind === 'tool_started');

    return lastTool?.text ?? null;
}

/** `Tentar de novo` (RF-07): only on failed tasks, one request per click. */
function retry(task: TaskSummary): void {
    if (task.status !== 'failed' || tasksStore.acting) {
        return;
    }

    void tasksStore.retry(String(task.id));
}

function viewLog(task: TaskSummary): void {
    void tasksStore.openDetail(String(task.id));
}
</script>

<template>
    <section class="glass chamfer-md flex min-h-0 flex-col gap-3 p-3" data-testid="agents-panel">
        <header class="flex items-center justify-between gap-2">
            <h2 class="label-caps text-label text-cyan-300">{{ t('agentsPanel.title') }}</h2>
            <span
                v-if="counter !== null"
                class="label-caps chamfer-sm border px-2 py-1 font-mono text-[0.65rem]"
                :class="[counterClasses[counter.kind], { 'agents-counter--pulse': counter.kind === 'active' && !reducedMotion }]"
                :data-kind="counter.kind"
                data-testid="agents-counter"
            >
                {{ counterText }}
            </span>
        </header>

        <p v-if="tasks.length === 0" class="font-mono text-[0.75rem] text-text-400" data-testid="agents-empty">
            {{ t('agentsPanel.empty') }}
        </p>

        <ul v-else class="flex min-h-0 flex-col gap-3 overflow-y-auto">
            <li
                v-for="task in tasks"
                :key="task.id"
                class="chamfer-sm flex flex-col gap-2 border bg-abyss-950/40 p-2.5"
                :class="[statusClasses(task), task.status === 'cancelled' ? 'opacity-70' : '']"
                :data-status="task.status"
                :data-testid="`agents-card-${task.id}`"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="agents-status__icon relative inline-flex size-2.5 shrink-0"
                        :class="{
                            'agents-status__icon--pulse': task.status === 'waiting_approval' && !reducedMotion,
                            'agents-status__icon--ring': task.status === 'completed' && !reducedMotion,
                        }"
                        data-dot
                        aria-hidden="true"
                        data-testid="agents-card-status-icon"
                    ></span>
                    <span class="label-caps font-mono text-[0.65rem] text-cyan-300">{{ t('agents.task') }}</span>
                    <span
                        v-if="modelAndAgent(task) !== ''"
                        class="min-w-0 truncate font-mono text-[0.65rem] text-text-400"
                        data-testid="agents-card-model"
                    >
                        {{ modelAndAgent(task) }}
                    </span>
                    <time
                        class="ml-auto font-mono text-[0.75rem] tabular-nums text-text-100"
                        :aria-label="t('agentsPanel.elapsed')"
                        data-testid="agents-card-timer"
                    >
                        {{ elapsed(task) }}
                    </time>
                    <button
                        v-if="canStop(task)"
                        type="button"
                        class="label-caps chamfer-sm inline-flex min-h-11 min-w-11 items-center justify-center border border-danger-400/70 px-2 text-[0.65rem] text-danger-400 hover:bg-danger-400/15 disabled:opacity-50"
                        :disabled="timeline.stopping[String(task.id)] === true"
                        data-testid="agents-card-stop"
                        @click="timeline.stop(task.id)"
                    >
                        {{ timeline.stopping[String(task.id)] === true ? t('agentsPanel.stopping') : t('agents.stop') }}
                    </button>
                    <span v-else class="label-caps font-mono text-[0.65rem]" data-testid="agents-card-status">
                        {{ t(`status.${task.status}`) }}
                    </span>
                </div>

                <p class="line-clamp-2 text-[0.8rem] text-text-100" :title="task.title" data-testid="agents-card-description">{{ task.title }}</p>

                <p
                    v-if="timeline.stopErrors[String(task.id)]"
                    class="text-[0.75rem] text-danger-400"
                    role="alert"
                    data-testid="agents-card-stop-error"
                >
                    {{ timeline.stopErrors[String(task.id)] }}
                </p>

                <div v-if="task.status === 'failed'" class="flex min-w-0 flex-col gap-1.5" data-testid="agents-card-failure">
                    <p class="label-caps text-label text-danger-400" data-testid="agents-card-failure-title">{{ t('agentsPanel.failed') }}</p>
                    <p v-if="failedTool(task) !== null" class="truncate font-mono text-[0.65rem] text-text-400" data-testid="agents-card-failure-tool">
                        {{ failedTool(task) }}
                    </p>
                    <p class="text-[0.75rem] text-danger-400" data-testid="agents-card-error">{{ failureLabel(task) }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="label-caps chamfer-sm inline-flex min-h-11 items-center border border-danger-400/70 px-3 text-[0.7rem] text-danger-400 hover:bg-danger-400/15 disabled:opacity-50"
                            :disabled="tasksStore.acting"
                            data-testid="agents-card-retry"
                            @click="retry(task)"
                        >
                            {{ t('edge.taskFailed.retry') }}
                        </button>
                        <button
                            type="button"
                            class="label-caps inline-flex min-h-11 items-center px-2 text-[0.7rem] text-cyan-300 underline-offset-4 hover:underline"
                            data-testid="agents-card-view-log"
                            @click="viewLog(task)"
                        >
                            {{ t('edge.taskFailed.viewLog') }}
                        </button>
                    </div>
                </div>

                <div
                    v-if="timeline.currentItem(task.id) !== null && !TERMINAL_TASK_STATUSES.has(task.status)"
                    class="chamfer-sm flex min-w-0 flex-col border border-cyan-300/40 bg-cyan-300/10 px-2 py-1"
                    :aria-label="t('agentsPanel.currentItem')"
                    data-testid="agents-card-current"
                >
                    <span class="truncate font-mono text-[0.75rem] text-biolum-200">{{ timeline.currentItem(task.id)?.text }}</span>
                    <span v-if="timeline.currentItem(task.id)?.detail" class="truncate font-mono text-[0.65rem] text-text-400">
                        {{ timeline.currentItem(task.id)?.detail }}
                    </span>
                </div>

                <TaskTimeline
                    v-if="timeline.entriesFor(task.id).length > 0"
                    :entries="timeline.entriesFor(task.id)"
                    :agent-name="task.agent?.name ?? null"
                />
            </li>
        </ul>
    </section>
</template>

<style scoped>
.agents-status__icon {
    border-radius: 50%;
    background-color: currentColor;
}

/* `N ATIVO(S)` breathes while tasks run (UI-11). */
.agents-counter--pulse {
    animation: agents-pulse 1.6s ease-in-out infinite;
}

/* Waiting for approval: amber dot pulsing. */
.agents-status__icon--pulse {
    animation: agents-pulse 1.2s ease-in-out infinite;
}

/* Completed: a single ring expands and fades, once (never infinite). */
.agents-status__icon--ring::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 50%;
    border: 1px solid currentColor;
    opacity: 0;
    animation: agents-ring var(--dur-glow) var(--ease-sonar) 1;
}

@keyframes agents-pulse {
    0%,
    100% {
        opacity: 1;
    }

    50% {
        opacity: 0.45;
    }
}

@keyframes agents-ring {
    from {
        transform: scale(1);
        opacity: 0.9;
    }

    to {
        transform: scale(3.2);
        opacity: 0;
    }
}
</style>
