import { useApprovalsStore } from '@/stores/approvals';
import { useNotificationsStore } from '@/stores/notifications';

/** Echo-compatible channel; the real one comes from `useRealtimeConnection`. */
export interface MessageChannel {
    listen(event: string, callback: (payload: unknown) => void): unknown;
}

interface NotificationEventPayload {
    type?: unknown;
}

/**
 * Approval-related channel events (US-8.3): an `approval_pending`
 * notification bumps the header badge and refreshes an open pending list.
 */
export function bindApprovalEvents(channel: MessageChannel): void {
    const notifications = useNotificationsStore();
    const approvals = useApprovalsStore();

    channel.listen('notification.created', (payload: unknown) => {
        const type = (payload as NotificationEventPayload | null)?.type;

        if (type !== 'approval_pending') {
            return;
        }

        notifications.increment();
        void approvals.refreshIfLoaded();
    });
}
