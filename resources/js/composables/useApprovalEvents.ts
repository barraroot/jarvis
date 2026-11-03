import { useApprovalsStore } from '@/stores/approvals';

/** Echo-compatible channel; the real one comes from `useRealtimeConnection`. */
export interface MessageChannel {
    listen(event: string, callback: (payload: unknown) => void): unknown;
}

interface NotificationEventPayload {
    type?: unknown;
}

/**
 * Approval-related channel events (US-8.3): an `approval_pending`
 * notification refreshes an open pending list. The notification counter and
 * list are handled by `useNotificationEvents`.
 */
export function bindApprovalEvents(channel: MessageChannel): void {
    const approvals = useApprovalsStore();

    channel.listen('.notification.created', (payload: unknown) => {
        const type = (payload as NotificationEventPayload | null)?.type;

        if (type !== 'approval_pending') {
            return;
        }

        void approvals.refreshIfLoaded();
    });
}
