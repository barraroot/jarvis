import { describe, expect, it } from 'vitest';
import { bindApprovalEvents, type MessageChannel } from '@/composables/useApprovalEvents';
import { useNotificationsStore } from '@/stores/notifications';

/** In-memory channel: stores callbacks keyed by event name. */
function fakeChannel(): MessageChannel & { emit(event: string, payload: unknown): void; listeners: Map<string, (payload: unknown) => void> } {
    const listeners = new Map<string, (payload: unknown) => void>();

    return {
        listeners,
        listen(event, callback) {
            listeners.set(event, callback);
        },
        emit(event, payload) {
            listeners.get(`.${event}`)?.(payload);
        },
    };
}

describe('bindApprovalEvents', () => {
    it('refreshes the pending list on approval_pending and never touches the badge', () => {
        const channel = fakeChannel();
        const notifications = useNotificationsStore();

        bindApprovalEvents(channel);

        // The header badge is fed by bindNotificationEvents (US-10.4);
        // this binding only refreshes an open approvals list.
        channel.emit('notification.created', { id: '1', type: 'approval_pending' });
        expect(notifications.unreadCount).toBe(0);

        channel.emit('notification.created', { id: '2', type: 'task_completed' });
        expect(notifications.unreadCount).toBe(0);

        channel.emit('notification.created', {});
        expect(notifications.unreadCount).toBe(0);
    });
});
