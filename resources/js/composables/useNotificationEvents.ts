import { translate } from '@/i18n';
import { useNotificationsStore } from '@/stores/notifications';
import { usePreferencesStore } from '@/stores/preferences';
import { useToastStore, type ToastVariant } from '@/stores/toast';
import type { NotificationSummary } from '@/types/models';

/** Echo-compatible channel; the real one comes from `useRealtimeConnection`. */
export interface MessageChannel {
    listen(event: string, callback: (payload: unknown) => void): unknown;
}

/** Toast accent per notification type (UI-25); unknown types stay `info`. */
const NOTIFICATION_VARIANTS: Readonly<Record<string, ToastVariant>> = {
    approval_pending: 'warning',
    task_completed: 'success',
    task_failed: 'danger',
    connector_error: 'danger',
    usage_limit_reached: 'danger',
};

/** Maps a notification `type` to the toast variant that colors its accent (UI-25). */
export function notificationToastVariant(type: string | null | undefined): ToastVariant {
    if (typeof type !== 'string' || !Object.hasOwn(NOTIFICATION_VARIANTS, type)) {
        return 'info';
    }

    return NOTIFICATION_VARIANTS[type] ?? 'info';
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

    channel.listen('.notification.created', (payload: unknown) => {
        const notification = payload as NotificationSummary | null;

        if (typeof notification?.id !== 'string' || typeof notification.title !== 'string') {
            return;
        }

        notifications.incoming(notification);

        if (preferences.preference?.extra?.toasts !== false) {
            toasts.push(notification.body ?? notification.title, {
                variant: notificationToastVariant(notification.type),
                timeoutMs: 6000,
            });
        }
    });
}
