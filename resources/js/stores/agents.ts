import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { ActiveAgent } from '@/types/models';

/**
 * Agents currently working, fed by `agent.*` events (Phase 11) via Echo.
 * Empty until then: the side panel renders EmptyState, never fake data.
 */
export const useAgentsStore = defineStore('agents', () => {
    const agents = ref<ActiveAgent[]>([]);

    function upsert(agent: ActiveAgent): void {
        const index = agents.value.findIndex((item) => item.name === agent.name);

        if (index === -1) {
            agents.value.push(agent);
        } else {
            agents.value[index] = agent;
        }
    }

    function remove(name: string): void {
        agents.value = agents.value.filter((agent) => agent.name !== name);
    }

    function clear(): void {
        agents.value = [];
    }

    return { agents, upsert, remove, clear };
});
