import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { API_PREFIX, http } from '@/lib/http';
import type { AiModelSummary } from '@/types/models';

interface ModelsEnvelope {
    data: AiModelSummary[];
}

export interface ProviderSummary {
    name: string;
    count: number;
}

/**
 * Text models offered by the AI brain (`GET /api/v1/ai/models`, US-3.5): feeds
 * the conversation `ModelSelector` and the side panel "LLM status". Errors are
 * left to the callers so each surface can render its own message.
 */
export const useModelsStore = defineStore('models', () => {
    const models = ref<AiModelSummary[]>([]);
    const loading = ref(false);
    const loaded = ref(false);

    const defaultModel = computed(() => models.value.find((model) => model.is_default) ?? null);

    /** Connected providers (all listed models come from active providers). */
    const providers = computed<ProviderSummary[]>(() => {
        const groups = new Map<string, number>();

        for (const model of models.value) {
            groups.set(model.provider, (groups.get(model.provider) ?? 0) + 1);
        }

        return [...groups.entries()].map(([name, count]) => ({ name, count }));
    });

    async function load(force: boolean = false): Promise<AiModelSummary[]> {
        if (loaded.value && !force) {
            return models.value;
        }

        loading.value = true;

        try {
            const response = await http.get<ModelsEnvelope>(`${API_PREFIX}/ai/models`);
            models.value = response.data;
            loaded.value = true;

            return models.value;
        } finally {
            loading.value = false;
        }
    }

    return { models, loading, loaded, defaultModel, providers, load };
});
