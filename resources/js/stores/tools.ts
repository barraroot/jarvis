import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { ToolState } from '@/types/models';

/**
 * Tool status rows for the side panel, fed by `tool.*` events (Phase 11).
 * Empty until then: the panel renders EmptyState, never fake data.
 */
export const useToolsStore = defineStore('tools', () => {
    const tools = ref<ToolState[]>([]);

    function upsert(tool: ToolState): void {
        const index = tools.value.findIndex((item) => item.id === tool.id);

        if (index === -1) {
            tools.value.push(tool);
        } else {
            tools.value[index] = tool;
        }
    }

    function clear(): void {
        tools.value = [];
    }

    return { tools, upsert, clear };
});
