import { defineStore } from 'pinia';
import { reactive, ref } from 'vue';
import { API_PREFIX, http } from '@/lib/http';
import type { ActivityFilters, ActivityItem } from '@/types/models';

interface ActivityPageEnvelope {
    data: ActivityItem[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
}

/**
 * The operator's own activity history for `/activity` (US-12.4): agent runs,
 * tool runs and approval decisions from `GET /api/v1/activity`, filtered by
 * period and type, 20 per page. Distinct from the live terminal feed
 * (`useActivityStore`), which only holds this session's events.
 */
export const useActivityHistoryStore = defineStore('activityHistory', () => {
    const items = ref<ActivityItem[]>([]);
    const filters = reactive<ActivityFilters>({ from: '', to: '', type: '' });
    const page = ref(1);
    const lastPage = ref(1);
    const total = ref(0);
    const loading = ref(false);
    const error = ref<string | null>(null);

    function url(targetPage: number): string {
        const params = new URLSearchParams();

        if (filters.from) {
            params.set('from', filters.from);
        }

        if (filters.to) {
            params.set('to', filters.to);
        }

        if (filters.type) {
            params.set('type', filters.type);
        }

        params.set('page', String(targetPage));

        return `${API_PREFIX}/activity?${params.toString()}`;
    }

    async function load(targetPage = 1): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            const response = await http.get<ActivityPageEnvelope>(url(targetPage));

            items.value = response.data;
            page.value = response.meta.current_page;
            lastPage.value = response.meta.last_page;
            total.value = response.meta.total;
        } catch (caught) {
            error.value = 'network';

            throw caught;
        } finally {
            loading.value = false;
        }
    }

    return { items, filters, page, lastPage, total, loading, error, load };
});
