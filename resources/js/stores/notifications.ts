import { defineStore } from 'pinia';
import { ref } from 'vue';

/**
 * Notification counter for the header, fed by `notification.created` (Phase 13).
 * Empty until then: the badge stays hidden.
 */
export const useNotificationsStore = defineStore('notifications', () => {
    const unreadCount = ref(0);

    function setUnreadCount(count: number): void {
        unreadCount.value = Math.max(0, Math.trunc(count));
    }

    return { unreadCount, setUnreadCount };
});
