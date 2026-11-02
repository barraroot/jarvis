import { defineStore } from 'pinia';
import { ref } from 'vue';

/**
 * Notification counter for the header, fed by `notification.created`. The
 * counter only tracks unread approval notifications for now; the full
 * notification centre lands with the retention phase.
 */
export const useNotificationsStore = defineStore('notifications', () => {
    const unreadCount = ref(0);

    function setUnreadCount(count: number): void {
        unreadCount.value = Math.max(0, Math.trunc(count));
    }

    function increment(): void {
        unreadCount.value += 1;
    }

    return { unreadCount, setUnreadCount, increment };
});
