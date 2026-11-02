import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { ActivityEntry } from '@/types/models';

/** Terminal keeps the most recent entries; older lines fall off the visible tail. */
const MAX_ENTRIES = 200;

/**
 * Terminal-style execution history (agents, tools), fed by events (Phase 13).
 * Empty until then: the side panel renders EmptyState, never fake data.
 */
export const useActivityStore = defineStore('activity', () => {
    /** Newest first, so the panel can render top-down with timestamps. */
    const entries = ref<ActivityEntry[]>([]);

    function push(entry: ActivityEntry): void {
        entries.value.unshift(entry);

        if (entries.value.length > MAX_ENTRIES) {
            entries.value.length = MAX_ENTRIES;
        }
    }

    function clear(): void {
        entries.value = [];
    }

    return { entries, push, clear };
});
