import { defineStore } from 'pinia';
import { ref } from 'vue';

/**
 * MCP connector counts for the header, fed by `connector.status.changed` (Phase 15).
 * Empty until then: the indicator stays hidden.
 */
export const useConnectorsStore = defineStore('connectors', () => {
    const connectedCount = ref(0);
    const errorCount = ref(0);

    function setCounts(connected: number, errors: number): void {
        connectedCount.value = Math.max(0, Math.trunc(connected));
        errorCount.value = Math.max(0, Math.trunc(errors));
    }

    return { connectedCount, errorCount, setCounts };
});
