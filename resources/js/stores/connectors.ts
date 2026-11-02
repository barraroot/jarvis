import { defineStore } from 'pinia';
import { ref } from 'vue';
import { API_PREFIX, http } from '@/lib/http';
import type { ConnectorStatusChange, ConnectorSummary, ConnectorTool } from '@/types/models';

interface ConnectorListEnvelope {
    data: ConnectorSummary[];
}

interface ConnectorEnvelope {
    data: ConnectorSummary;
}

interface ConnectorToolListEnvelope {
    data: ConnectorTool[];
}

interface ConnectorToolEnvelope {
    data: ConnectorTool;
}

export interface ConnectorInput {
    name: string;
    url: string;
    credential?: string | null;
}

export type ToolClassification = Partial<Pick<ConnectorTool, 'is_write' | 'is_sensitive' | 'is_active'>>;

/**
 * MCP connectors (US-9.1/9.2/9.4): active global connectors plus the
 * operator's personal ones, their discovered tools (US-9.3) and the header
 * counts, kept live by `connector.status.changed`.
 */
export const useConnectorsStore = defineStore('connectors', () => {
    const connectors = ref<ConnectorSummary[]>([]);
    const toolsByConnector = ref<Record<number, ConnectorTool[]>>({});
    const loading = ref(false);
    const loaded = ref(false);
    const connectedCount = ref(0);
    const errorCount = ref(0);

    function setCounts(connected: number, errors: number): void {
        connectedCount.value = Math.max(0, Math.trunc(connected));
        errorCount.value = Math.max(0, Math.trunc(errors));
    }

    function recount(): void {
        const active = connectors.value.filter((connector) => connector.is_active);

        setCounts(
            active.filter((connector) => connector.status === 'connected').length,
            active.filter((connector) => connector.status === 'error').length,
        );
    }

    function upsert(connector: ConnectorSummary): void {
        const index = connectors.value.findIndex((candidate) => candidate.id === connector.id);

        if (index === -1) {
            connectors.value.push(connector);
        } else {
            connectors.value.splice(index, 1, connector);
        }

        recount();
    }

    async function load(): Promise<ConnectorSummary[]> {
        loading.value = true;

        try {
            const response = await http.get<ConnectorListEnvelope>(`${API_PREFIX}/connectors`);

            connectors.value = response.data;
            loaded.value = true;
            recount();

            return response.data;
        } finally {
            loading.value = false;
        }
    }

    async function create(input: ConnectorInput): Promise<ConnectorSummary> {
        const response = await http.post<ConnectorEnvelope>(`${API_PREFIX}/connectors`, input);

        upsert(response.data);

        return response.data;
    }

    async function update(id: number, input: Partial<ConnectorInput> & { is_active?: boolean }): Promise<ConnectorSummary> {
        const response = await http.patch<ConnectorEnvelope>(`${API_PREFIX}/connectors/${id}`, input);

        upsert(response.data);

        return response.data;
    }

    async function remove(id: number): Promise<void> {
        await http.delete(`${API_PREFIX}/connectors/${id}`);

        connectors.value = connectors.value.filter((connector) => connector.id !== id);
        delete toolsByConnector.value[id];
        recount();
    }

    async function resync(id: number): Promise<void> {
        await http.post(`${API_PREFIX}/connectors/${id}/resync`);
    }

    async function loadTools(id: number): Promise<ConnectorTool[]> {
        const response = await http.get<ConnectorToolListEnvelope>(`${API_PREFIX}/connectors/${id}/tools`);

        toolsByConnector.value[id] = response.data;

        return response.data;
    }

    async function classifyTool(connectorId: number, toolId: number, classification: ToolClassification): Promise<ConnectorTool> {
        const response = await http.patch<ConnectorToolEnvelope>(
            `${API_PREFIX}/connectors/${connectorId}/tools/${toolId}`,
            classification,
        );

        const tools = toolsByConnector.value[connectorId] ?? [];
        const index = tools.findIndex((tool) => tool.id === toolId);

        if (index !== -1) {
            tools.splice(index, 1, response.data);
        }

        return response.data;
    }

    /** `connector.status.changed`: patch the known connector; unknown ones trigger a reload. */
    function applyStatusChange(change: ConnectorStatusChange): void {
        const current = connectors.value.find((connector) => connector.id === change.id);

        if (current === undefined) {
            void load().catch(() => undefined);

            return;
        }

        upsert({
            ...current,
            status: change.status,
            last_error: change.last_error,
            last_checked_at: change.last_checked_at,
            last_synced_at: change.last_synced_at,
        });

        // A finished sync may have changed the discovered tools.
        if (toolsByConnector.value[change.id] !== undefined) {
            void loadTools(change.id).catch(() => undefined);
        }
    }

    return {
        connectors,
        toolsByConnector,
        loading,
        loaded,
        connectedCount,
        errorCount,
        setCounts,
        load,
        create,
        update,
        remove,
        resync,
        loadTools,
        classifyTool,
        applyStatusChange,
    };
});
