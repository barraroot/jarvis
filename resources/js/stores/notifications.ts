import { defineStore } from 'pinia';
import { ref } from 'vue';
import { API_PREFIX, http } from '@/lib/http';
import type { NotificationSummary } from '@/types/models';

interface NotificationListEnvelope {
    data: NotificationSummary[];
}

interface NotificationEnvelope {
    data: NotificationSummary;
}

const MAX_ITEMS = 50;

/**
 * Notification centre (US-10.4): the header counter and the dropdown list,
 * fed by `notification.created` and restored from `/auth/me` (count) and
 * `GET /notifications` (list, lazily when opened).
 */
export const useNotificationsStore = defineStore('notifications', () => {
    const items = ref<NotificationSummary[]>([]);
    const unreadCount = ref(0);
    const loading = ref(false);
    const loaded = ref(false);
    const error = ref<string | null>(null);

    function setUnreadCount(count: number): void {
        unreadCount.value = Math.max(0, Math.trunc(count));
    }

    function increment(): void {
        unreadCount.value += 1;
    }

    function upsert(notification: NotificationSummary): void {
        const index = items.value.findIndex((item) => item.id === notification.id);

        if (index === -1) {
            items.value.unshift(notification);
            items.value = items.value.slice(0, MAX_ITEMS);
        } else {
            items.value[index] = notification;
        }
    }

    /** A `notification.created` payload arrives in real time. */
    function incoming(notification: NotificationSummary): void {
        upsert(notification);

        if (notification.read_at === null) {
            increment();
        }
    }

    async function load(): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            const response = await http.get<NotificationListEnvelope>(`${API_PREFIX}/notifications`);

            items.value = response.data;
            loaded.value = true;
        } catch {
            error.value = 'network';
        } finally {
            loading.value = false;
        }
    }

    async function markRead(id: string): Promise<void> {
        try {
            const response = await http.post<NotificationEnvelope>(`${API_PREFIX}/notifications/${id}/read`);

            upsert(response.data);

            if (response.data.read_at !== null) {
                setUnreadCount(Math.max(0, unreadCount.value - 1));
            }
        } catch {
            // The counter stays conservative; the next refresh reconciles it.
        }
    }

    async function markAllRead(): Promise<void> {
        try {
            await http.post(`${API_PREFIX}/notifications/read-all`);

            items.value = items.value.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() }));
            setUnreadCount(0);
        } catch {
            // Same as above: keep the local state, reconcile on next load.
        }
    }

    function clear(): void {
        items.value = [];
        unreadCount.value = 0;
        loaded.value = false;
        error.value = null;
    }

    return {
        items,
        unreadCount,
        loading,
        loaded,
        error,
        setUnreadCount,
        increment,
        upsert,
        incoming,
        load,
        markRead,
        markAllRead,
        clear,
    };
});
