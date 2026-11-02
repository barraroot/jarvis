import { translate } from '@/i18n';
import { useNotificationsStore } from '@/stores/notifications';
import { usePreferencesStore } from '@/stores/preferences';
import { useToastStore } from '@/stores/toast';
import type { NotificationSummary } from '@/types/models';

/** Echo-compatible channel; the real one comes from `useRealtimeConnection`. */
export interface MessageChannel {
    listen(event: string, callback: (payload: unknown) => void): unknown;
}

/**
 * `notification.created` events (US-10.4): update the header counter and the
 * dropdown list, and show a discreet toast unless the operator disabled
 * toasts in their preferences (`extra.toasts = false`).
 */
export function bindNotificationEvents(channel: MessageChannel): void {
    const notifications = useNotificationsStore();
    const preferences = usePreferencesStore();
    const toasts = useToastStore();

    channel.listen('notification.created', (payload: unknown) => {
        const notification = payload as NotificationSummary | null;

        if (typeof notification?.id !== 'string' || typeof notification.title !== 'string') {
            return;
        }

        notifications.incoming(notification);

        if (preferences.preference?.extra?.toasts !== false) {
            toasts.push(notification.body ?? notification.title, { variant: 'info', timeoutMs: 6000 });
        }
    });
}
