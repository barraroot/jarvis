import { defineStore } from 'pinia';
import { ref } from 'vue';

/**
 * Monthly cost limit edge state (RF-09): a `402` on a message send or a voice
 * session/task stores the server `message` verbatim — never a monetary value
 * computed by the SPA. Null while no limit was reported; an empty string when
 * the response carried no message (the edge state then shows only its title).
 */
export const useLimitsStore = defineStore('limits', () => {
    const costLimitMessage = ref<string | null>(null);

    function reportCostLimit(message: string | null): void {
        costLimitMessage.value = message ?? '';
    }

    function clearCostLimit(): void {
        costLimitMessage.value = null;
    }

    return { costLimitMessage, reportCostLimit, clearCostLimit };
});
