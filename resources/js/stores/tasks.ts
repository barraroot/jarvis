import { defineStore } from 'pinia';
import { ref } from 'vue';
import { API_PREFIX, HttpError, http } from '@/lib/http';
import { useToastStore } from '@/stores/toast';
import { translate } from '@/i18n';
import type { TaskDetail, TaskSummary } from '@/types/models';

interface TaskListEnvelope {
    data: TaskSummary[];
}

interface TaskEnvelope {
    data: TaskDetail;
}

const TERMINAL = new Set(['completed', 'failed', 'cancelled']);

/**
 * Background tasks (US-10.2, US-10.3): the side panel list is restored from
 * `GET /tasks?active=1` on reload and kept live by `task.updated`; terminal
 * tasks leave the list. The detail modal fetches steps/logs/result on demand
 * and exposes the cancel/retry actions.
 */
export const useTasksStore = defineStore('tasks', () => {
    const tasks = ref<TaskSummary[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const detail = ref<TaskDetail | null>(null);
    const detailOpen = ref(false);
    const detailLoading = ref(false);
    const acting = ref(false);

    function upsert(task: TaskSummary): void {
        const index = tasks.value.findIndex((item) => item.id === task.id);

        if (index === -1) {
            tasks.value.push(task);
        } else {
            tasks.value[index] = task;
        }
    }

    function remove(id: string): void {
        tasks.value = tasks.value.filter((task) => task.id !== id);
    }

    /** Restores active tasks on page reload (US-10.2). */
    async function load(): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            const response = await http.get<TaskListEnvelope>(`${API_PREFIX}/tasks?active=1`);

            tasks.value = response.data;
        } catch {
            error.value = 'network';
        } finally {
            loading.value = false;
        }
    }

    /** Applies a `task.updated` snapshot to the list and the open detail. */
    function applyEvent(task: TaskSummary): void {
        if (detail.value?.id === task.id) {
            detail.value = { ...detail.value, ...task };
        }

        if (TERMINAL.has(task.status)) {
            remove(task.id);

            return;
        }

        upsert(task);
    }

    async function openDetail(id: string): Promise<void> {
        detailOpen.value = true;
        detailLoading.value = true;

        try {
            const response = await http.get<TaskEnvelope>(`${API_PREFIX}/tasks/${id}`);

            detail.value = response.data;
        } catch {
            useToastStore().push(translate('errors.network'), { variant: 'danger' });
            detailOpen.value = false;
        } finally {
            detailLoading.value = false;
        }
    }

    function closeDetail(): void {
        detailOpen.value = false;
    }

    async function cancel(id: string): Promise<void> {
        acting.value = true;

        try {
            const response = await http.post<TaskEnvelope>(`${API_PREFIX}/tasks/${id}/cancel`);

            applyEvent(response.data);
        } catch {
            useToastStore().push(translate('errors.network'), { variant: 'danger' });
        } finally {
            acting.value = false;
        }
    }

    async function retry(id: string): Promise<void> {
        acting.value = true;

        try {
            const response = await http.post<TaskEnvelope>(`${API_PREFIX}/tasks/${id}/retry`);

            upsert(response.data);
            useToastStore().push(translate('tasks.retryQueued'), { variant: 'success' });
        } catch (error) {
            const serverMessage = error instanceof HttpError ? error.serverMessage : null;

            useToastStore().push(serverMessage !== null && serverMessage !== '' ? serverMessage : translate('errors.network'), {
                variant: 'danger',
            });
        } finally {
            acting.value = false;
        }
    }

    function clear(): void {
        tasks.value = [];
        detail.value = null;
        detailOpen.value = false;
        error.value = null;
    }

    return {
        tasks,
        loading,
        error,
        detail,
        detailOpen,
        detailLoading,
        acting,
        upsert,
        remove,
        applyEvent,
        load,
        openDetail,
        closeDetail,
        cancel,
        retry,
        clear,
    };
});
