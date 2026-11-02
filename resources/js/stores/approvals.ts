import { defineStore } from 'pinia';
import { ref } from 'vue';
import { API_PREFIX, http } from '@/lib/http';
import type { ApprovalSummary } from '@/types/models';

interface ApprovalListEnvelope {
    data: ApprovalSummary[];
}

interface ApprovalEnvelope {
    data: ApprovalSummary;
}

/**
 * The operator's approval requests (US-8.3): pending list for `/approvals`,
 * single fetch for chat cards, and approve/deny decisions. After a decision
 * the item is replaced by the server's representation, so cards render the
 * outcome without a reload.
 */
export const useApprovalsStore = defineStore('approvals', () => {
    const items = ref<ApprovalSummary[]>([]);
    const loading = ref(false);
    const loaded = ref(false);
    const error = ref<string | null>(null);

    async function load(): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            const response = await http.get<ApprovalListEnvelope>(`${API_PREFIX}/approvals?status=pending`);

            items.value = response.data;
            loaded.value = true;
        } catch {
            error.value = 'network';
        } finally {
            loading.value = false;
        }
    }

    /** Refreshes only when a pending list was already shown (avoids surprise fetches). */
    async function refreshIfLoaded(): Promise<void> {
        if (loaded.value) {
            await load().catch(() => undefined);
        }
    }

    async function fetch(id: string): Promise<ApprovalSummary> {
        const response = await http.get<ApprovalEnvelope>(`${API_PREFIX}/approvals/${id}`);

        upsert(response.data);

        return response.data;
    }

    function upsert(approval: ApprovalSummary): void {
        const index = items.value.findIndex((item) => item.id === approval.id);

        if (index === -1) {
            items.value.push(approval);
        } else {
            items.value[index] = approval;
        }
    }

    async function approve(id: string): Promise<ApprovalSummary> {
        const response = await http.post<ApprovalEnvelope>(`${API_PREFIX}/approvals/${id}/approve`);

        upsert(response.data);

        return response.data;
    }

    async function deny(id: string, reason?: string): Promise<ApprovalSummary> {
        const response = await http.post<ApprovalEnvelope>(`${API_PREFIX}/approvals/${id}/deny`, { reason });

        upsert(response.data);

        return response.data;
    }

    function clear(): void {
        items.value = [];
        loaded.value = false;
        error.value = null;
    }

    return { items, loading, loaded, error, load, refreshIfLoaded, fetch, approve, deny, clear };
});
