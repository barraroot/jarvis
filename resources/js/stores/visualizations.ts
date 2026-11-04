import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { translate } from '@/i18n';
import { API_PREFIX, http } from '@/lib/http';
import { useToastStore } from '@/stores/toast';
import type { Visualization } from '@/types/models';

interface VisualizationEnvelope {
    data: Visualization | null;
}

function isVisualization(value: unknown): value is Visualization {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const candidate = value as Partial<Visualization>;

    return typeof candidate.id === 'number' && Array.isArray(candidate.sections);
}

/**
 * The open progressive visualization (RF-16..RF-19b): restored on load
 * (CT-08), replaced wholesale by every `visualization.updated` snapshot
 * (CT-05) and closed by `×` (CT-09) without touching its task. Ids closed
 * locally are remembered so a late event cannot reopen the panel.
 */
export const useVisualizationsStore = defineStore('visualizations', () => {
    const current = ref<Visualization | null>(null);
    const closedIds = ref<number[]>([]);
    const loading = ref(false);

    const isOpen = computed(() => current.value !== null);

    function isClosedLocally(id: number): boolean {
        return closedIds.value.includes(id);
    }

    /** Applies a CT-05 snapshot; closed visualizations never come back (RF-19a). */
    function apply(visualization: unknown): void {
        if (!isVisualization(visualization) || isClosedLocally(visualization.id)) {
            return;
        }

        if (visualization.closed_at !== null) {
            if (current.value?.id === visualization.id) {
                current.value = null;
            }

            return;
        }

        current.value = visualization;
    }

    /** CT-08: the last visualization not closed by the operator, if any (RF-19b). */
    async function restore(): Promise<void> {
        loading.value = true;

        try {
            const response = await http.get<VisualizationEnvelope>(`${API_PREFIX}/visualizations/current`);

            if (response.data === null) {
                current.value = null;
            } else {
                apply(response.data);
            }
        } catch {
            // Without a restorable visualization the core simply stays centered.
        } finally {
            loading.value = false;
        }
    }

    /** CT-09: closes the panel at once; the linked task keeps running (RF-19a). */
    async function close(): Promise<void> {
        const visualization = current.value;

        if (visualization === null) {
            return;
        }

        closedIds.value = [...closedIds.value, visualization.id];
        current.value = null;

        try {
            await http.post<VisualizationEnvelope>(`${API_PREFIX}/visualizations/${visualization.id}/close`);
        } catch {
            useToastStore().push(translate('visualizations.closeError'), { variant: 'warning' });
        }
    }

    function clear(): void {
        current.value = null;
        closedIds.value = [];
    }

    return { current, closedIds, loading, isOpen, apply, restore, close, clear };
});
