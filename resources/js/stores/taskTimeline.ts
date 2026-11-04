import { defineStore } from 'pinia';
import { ref } from 'vue';
import { translate } from '@/i18n';
import { API_PREFIX, HttpError, http } from '@/lib/http';
import { useTasksStore } from '@/stores/tasks';
import type { TaskStatus, TaskSummary, ToolEventPayload } from '@/types/models';

export type TimelineEntryKind = 'tool_started' | 'tool_completed' | 'tool_failed' | 'progress_note';

/** One line of the AGENTES timeline (UI-11). */
export interface TimelineEntry {
    /** Stable key; repeated events with the same key are ignored. */
    key: string;
    taskId: string;
    kind: TimelineEntryKind;
    /** Tool display name, `Resultado recebido 1,5 KB`, failure label or the note itself. */
    text: string;
    /** Argument summary (started) or the pt-BR failure reason (failed). */
    detail: string | null;
    /** Tool execution the entry belongs to; null for progress notes. */
    toolExecutionId: number | null;
    /** Arrival order, which is the chronological order of the channel. */
    sequence: number;
}

/** Item highlighted on the task card (UI-10). */
export interface TimelineCurrentItem {
    text: string;
    detail: string | null;
    running: boolean;
}

/** Statuses that still accept `PARAR` (RF-14). */
export const STOPPABLE_STATUSES: ReadonlySet<TaskStatus> = new Set(['queued', 'running', 'waiting_approval']);

export const TERMINAL_TASK_STATUSES: ReadonlySet<TaskStatus> = new Set(['completed', 'failed', 'cancelled']);

const KILOBYTE = 1024;
const MEGABYTE = KILOBYTE * 1024;

/** `512 B`, `1,5 KB`, `2,0 MB` — one decimal, pt-BR comma (UI-11). */
export function formatBytes(bytes: number): string {
    const size = Math.max(0, Math.trunc(Number.isFinite(bytes) ? bytes : 0));
    const decimal = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

    if (size < KILOBYTE) {
        return translate('timeline.bytes', { n: size });
    }

    if (size < MEGABYTE) {
        return translate('timeline.kilobytes', { n: decimal.format(size / KILOBYTE) });
    }

    return translate('timeline.megabytes', { n: decimal.format(size / MEGABYTE) });
}

function positiveInteger(value: unknown): number | null {
    const number = Number(value);

    return Number.isInteger(number) && number > 0 ? number : null;
}

function isTask(value: unknown): value is TaskSummary {
    return typeof value === 'object' && value !== null && 'id' in value && 'status' in value;
}

interface CancelEnvelope {
    data: TaskSummary;
}

/**
 * Live timeline of every task shown on the AGENTES panel (UI-10, UI-11,
 * CT-03): `tool.*` entries keyed by `task_id`, `agent.progress` notes mapped
 * to the task through the `agent_execution_id` learned from `tool.*` (notes
 * that arrive before the mapping wait in a buffer). Task snapshots are kept
 * after they finish so the panel can show `CONCLUÍDO`/`FALHOU`/`CANCELADO`.
 * `PARAR` goes through `POST /tasks/{id}/cancel` (CT-06); a `409` stays on
 * the card as a pt-BR message.
 */
export const useTaskTimelineStore = defineStore('taskTimeline', () => {
    const entries = ref<Record<string, TimelineEntry[]>>({});
    const snapshots = ref<Record<string, TaskSummary>>({});
    const stopping = ref<Record<string, boolean>>({});
    const stopErrors = ref<Record<string, string>>({});
    /** agent execution id → task id. */
    const agentTasks = ref<Record<number, string>>({});
    /** agent execution id → parent agent execution id (delegation). */
    const agentParents = ref<Record<number, number>>({});
    /** Notes waiting for their agent execution to be mapped to a task. */
    const pendingNotes = ref<Record<number, string[]>>({});
    /** tool execution id → still running. */
    const runningTools = ref<Record<number, boolean>>({});

    let sequence = 0;

    function entriesFor(taskId: string | number): TimelineEntry[] {
        return entries.value[String(taskId)] ?? [];
    }

    function append(entry: Omit<TimelineEntry, 'sequence'>): void {
        const list = entries.value[entry.taskId] ?? [];

        if (list.some((item) => item.key === entry.key)) {
            return;
        }

        entries.value = { ...entries.value, [entry.taskId]: [...list, { ...entry, sequence: ++sequence }] };
    }

    function taskForAgent(agentExecutionId: number): string | null {
        const seen = new Set<number>();
        let current: number | undefined = agentExecutionId;

        while (current !== undefined && !seen.has(current)) {
            const taskId = agentTasks.value[current];

            if (taskId !== undefined) {
                return taskId;
            }

            seen.add(current);
            current = agentParents.value[current];
        }

        return null;
    }

    function appendNote(taskId: string, agentExecutionId: number, note: string): void {
        const list = entriesFor(taskId);
        const last = [...list].reverse().find((entry) => entry.kind === 'progress_note');

        if (last?.text === note) {
            return;
        }

        append({
            key: `note:${agentExecutionId}:${list.length}:${note}`,
            taskId,
            kind: 'progress_note',
            text: note,
            detail: null,
            toolExecutionId: null,
        });
    }

    /** Releases buffered notes of every agent execution that now resolves to a task. */
    function flushPendingNotes(): void {
        for (const [key, notes] of Object.entries(pendingNotes.value)) {
            const agentExecutionId = Number(key);
            const taskId = taskForAgent(agentExecutionId);

            if (taskId === null) {
                continue;
            }

            const { [agentExecutionId]: _released, ...rest } = pendingNotes.value;
            pendingNotes.value = rest;
            notes.forEach((note) => appendNote(taskId, agentExecutionId, note));
        }
    }

    function learnAgent(agentExecutionId: number | null, taskId: string): void {
        if (agentExecutionId === null || agentTasks.value[agentExecutionId] === taskId) {
            return;
        }

        agentTasks.value = { ...agentTasks.value, [agentExecutionId]: taskId };
        flushPendingNotes();
    }

    /** `tool.started` / `tool.completed` / `tool.failed` with the CT-03 payload (UI-11). */
    function applyToolEvent(kind: 'started' | 'completed' | 'failed', payload: unknown): void {
        const event = payload as Partial<ToolEventPayload> | null;
        const taskId = positiveInteger(event?.task_id);
        const executionId = positiveInteger(event?.execution_id);

        if (taskId === null || executionId === null) {
            return;
        }

        const task = String(taskId);
        const name = typeof event?.display_name === 'string' && event.display_name !== '' ? event.display_name : String(event?.tool ?? '');

        learnAgent(positiveInteger(event?.agent_execution_id), task);

        if (kind === 'started') {
            runningTools.value = { ...runningTools.value, [executionId]: true };
            append({
                key: `started:${executionId}`,
                taskId: task,
                kind: 'tool_started',
                text: name,
                detail: typeof event?.input_summary === 'string' && event.input_summary !== '' ? event.input_summary : null,
                toolExecutionId: executionId,
            });

            return;
        }

        runningTools.value = { ...runningTools.value, [executionId]: false };

        if (kind === 'completed') {
            const bytes = typeof event?.output_bytes === 'number' ? event.output_bytes : 0;

            append({
                key: `completed:${executionId}`,
                taskId: task,
                kind: 'tool_completed',
                text: translate('timeline.resultReceived', { size: formatBytes(bytes) }),
                detail: null,
                toolExecutionId: executionId,
            });

            return;
        }

        const status = typeof event?.status === 'string' && event.status !== '' ? event.status : 'failed';

        append({
            key: `failed:${executionId}`,
            taskId: task,
            kind: 'tool_failed',
            text: translate('timeline.toolFailed', { tool: name }),
            detail: translate(`status.${status}`),
            toolExecutionId: executionId,
        });
    }

    /** `agent.started`: remembers delegation so sub-agent notes reach the parent's task. */
    function linkAgent(payload: unknown): void {
        const event = payload as { execution_id?: unknown; parent_id?: unknown } | null;
        const executionId = positiveInteger(event?.execution_id);
        const parentId = positiveInteger(event?.parent_id);

        if (executionId === null || parentId === null) {
            return;
        }

        agentParents.value = { ...agentParents.value, [executionId]: parentId };
        flushPendingNotes();
    }

    /** `agent.progress.progress_note` (CT-06) as an italic timeline entry (UI-11). */
    function applyProgressNote(payload: unknown): void {
        const event = payload as { execution_id?: unknown; progress_note?: unknown } | null;
        const agentExecutionId = positiveInteger(event?.execution_id);
        const note = typeof event?.progress_note === 'string' ? event.progress_note.trim() : '';

        if (agentExecutionId === null || note === '') {
            return;
        }

        const taskId = taskForAgent(agentExecutionId);

        if (taskId === null) {
            const buffered = pendingNotes.value[agentExecutionId] ?? [];

            if (buffered[buffered.length - 1] !== note) {
                pendingNotes.value = { ...pendingNotes.value, [agentExecutionId]: [...buffered, note] };
            }

            return;
        }

        appendNote(taskId, agentExecutionId, note);
    }

    /** `task.updated` snapshot; terminal tasks stay listed with their final state (D4). */
    function applyTask(task: unknown): void {
        if (!isTask(task)) {
            return;
        }

        snapshots.value = { ...snapshots.value, [String(task.id)]: task };
    }

    /** Last tool started, highlighted on the card; falls back to the last note (UI-10). */
    function currentItem(taskId: string | number): TimelineCurrentItem | null {
        const list = entriesFor(taskId);
        const lastTool = [...list].reverse().find((entry) => entry.kind === 'tool_started');

        if (lastTool !== undefined) {
            return {
                text: lastTool.text,
                detail: lastTool.detail,
                running: lastTool.toolExecutionId !== null && runningTools.value[lastTool.toolExecutionId] === true,
            };
        }

        const lastNote = [...list].reverse().find((entry) => entry.kind === 'progress_note');

        return lastNote === undefined ? null : { text: lastNote.text, detail: null, running: false };
    }

    /** `PARAR` (RF-14, CT-06); `409` means the task already left a cancellable state. */
    async function stop(taskId: string | number): Promise<void> {
        const id = String(taskId);

        if (stopping.value[id] === true) {
            return;
        }

        const { [id]: _previous, ...remainingErrors } = stopErrors.value;
        stopErrors.value = remainingErrors;
        stopping.value = { ...stopping.value, [id]: true };

        try {
            const response = await http.post<CancelEnvelope>(`${API_PREFIX}/tasks/${id}/cancel`);

            applyTask(response.data);
            useTasksStore().applyEvent(response.data);
        } catch (error) {
            const message =
                error instanceof HttpError && error.status === 409
                    ? translate('agentsPanel.cancelConflict')
                    : translate('agentsPanel.cancelFailed');

            stopErrors.value = { ...stopErrors.value, [id]: message };
        } finally {
            const { [id]: _done, ...rest } = stopping.value;
            stopping.value = rest;
        }
    }

    function clear(): void {
        entries.value = {};
        snapshots.value = {};
        stopping.value = {};
        stopErrors.value = {};
        agentTasks.value = {};
        agentParents.value = {};
        pendingNotes.value = {};
        runningTools.value = {};
        sequence = 0;
    }

    return {
        entries,
        snapshots,
        stopping,
        stopErrors,
        pendingNotes,
        entriesFor,
        applyToolEvent,
        linkAgent,
        applyProgressNote,
        applyTask,
        currentItem,
        stop,
        clear,
    };
});
