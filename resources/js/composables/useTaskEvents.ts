import { useTasksStore } from '@/stores/tasks';
import type { TaskSummary } from '@/types/models';

/** Echo-compatible channel; the real one comes from `useRealtimeConnection`. */
export interface MessageChannel {
    listen(event: string, callback: (payload: unknown) => void): unknown;
}

interface TaskUpdatedPayload {
    task?: unknown;
}

/**
 * Feeds the side panel with real `task.updated` events (US-10.2): every
 * transition and throttled progress update replaces the card snapshot;
 * terminal tasks leave the active list. No polling.
 */
export function bindTaskEvents(channel: MessageChannel): void {
    const tasks = useTasksStore();

    channel.listen('task.updated', (payload: unknown) => {
        const task = (payload as TaskUpdatedPayload | null)?.task;

        if (typeof task !== 'object' || task === null || !('id' in task)) {
            return;
        }

        tasks.applyEvent(task as TaskSummary);
    });
}
