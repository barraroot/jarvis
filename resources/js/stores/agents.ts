import { defineStore } from 'pinia';
import { ref } from 'vue';
import { http } from '@/lib/http';
import type { AgentActivity, AgentHistoryEntry } from '@/types/models';

/** History keeps the most recent finished runs. */
const MAX_HISTORY = 50;

interface AgentEventPayload {
    execution_id?: unknown;
    agent?: unknown;
    depth?: unknown;
    parent_id?: unknown;
    status?: unknown;
    progress_note?: unknown;
}

interface AgentExecutionSummary {
    id: number;
    agent: { name: string; slug: string } | null;
    status: 'completed' | 'failed' | 'timed_out';
    depth: number;
    parent_id: number | null;
    duration_ms: number;
}

interface AgentExecutionsEnvelope {
    data: AgentExecutionSummary[];
}

/**
 * Agents currently working plus the finished-run history (US-7.4), fed by
 * `agent.*` events via Echo. The history is restored from the API on mount,
 * then kept fresh by events — never by polling.
 */
export const useAgentsStore = defineStore('agents', () => {
    const agents = ref<AgentActivity[]>([]);
    const history = ref<AgentHistoryEntry[]>([]);

    function start(payload: unknown): void {
        const event = payload as AgentEventPayload | null;
        const executionId = Number(event?.execution_id);

        if (!Number.isInteger(executionId) || executionId <= 0) {
            return;
        }

        const agent = typeof event?.agent === 'string' ? event.agent : '';

        upsertActivity({
            execution_id: executionId,
            agent,
            depth: Number(event?.depth ?? 0),
            parent_id: typeof event?.parent_id === 'number' ? event.parent_id : null,
            status: 'running',
            progress_note: typeof event?.progress_note === 'string' ? event.progress_note : null,
            started_at: Date.now(),
        });
    }

    function progress(payload: unknown): void {
        const event = payload as AgentEventPayload | null;
        const executionId = Number(event?.execution_id);
        const entry = agents.value.find((item) => item.execution_id === executionId);

        if (entry === undefined || typeof event?.progress_note !== 'string') {
            return;
        }

        entry.progress_note = event.progress_note;
    }

    function finish(payload: unknown): void {
        const event = payload as AgentEventPayload | null;
        const executionId = Number(event?.execution_id);

        if (!Number.isInteger(executionId) || executionId <= 0) {
            return;
        }

        const running = agents.value.find((item) => item.execution_id === executionId);
        const status = event?.status === 'timed_out' ? 'timed_out' : event?.status === 'completed' ? 'completed' : 'failed';

        agents.value = agents.value.filter((item) => item.execution_id !== executionId);

        history.value.unshift({
            execution_id: executionId,
            agent: typeof event?.agent === 'string' ? event.agent : (running?.agent ?? ''),
            depth: running?.depth ?? Number(event?.depth ?? 0),
            parent_id: running?.parent_id ?? (typeof event?.parent_id === 'number' ? event.parent_id : null),
            status,
            duration_ms: running !== undefined ? Math.max(0, Date.now() - running.started_at) : 0,
        });

        if (history.value.length > MAX_HISTORY) {
            history.value.length = MAX_HISTORY;
        }
    }

    /** Restores the finished history from `GET /api/v1/agent-executions`. */
    async function loadHistory(): Promise<void> {
        try {
            const response = await http.get<AgentExecutionsEnvelope>('/agent-executions');

            history.value = response.data.map((execution) => ({
                execution_id: execution.id,
                agent: execution.agent?.slug ?? execution.agent?.name ?? '',
                depth: execution.depth,
                parent_id: execution.parent_id,
                status: execution.status,
                duration_ms: execution.duration_ms,
            }));
        } catch {
            // History is best-effort; events keep filling it once the socket is up.
        }
    }

    function upsertActivity(activity: AgentActivity): void {
        const index = agents.value.findIndex((item) => item.execution_id === activity.execution_id);

        if (index === -1) {
            agents.value.push(activity);
        } else {
            agents.value[index] = activity;
        }
    }

    function clear(): void {
        agents.value = [];
        history.value = [];
    }

    return { agents, history, start, progress, finish, loadHistory, clear };
});
