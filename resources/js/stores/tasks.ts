import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { TaskCard } from '@/types/models';

/**
 * Background task cards for the side panel, fed by task events (Phase 12).
 * Empty until then: the panel renders EmptyState, never fake data.
 */
export const useTasksStore = defineStore('tasks', () => {
    const tasks = ref<TaskCard[]>([]);

    function upsert(task: TaskCard): void {
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

    function clear(): void {
        tasks.value = [];
    }

    return { tasks, upsert, remove, clear };
});
