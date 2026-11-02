import { describe, expect, it } from 'vitest';
import { bindApprovalEvents, type MessageChannel } from '@/composables/useApprovalEvents';
import { useNotificationsStore } from '@/stores/notifications';

function fakeChannel(): MessageChannel & { emit(event: string, payload: unknown): void; listeners: Map<string, (payload: unknown) => void> } {
    const listeners = new Map<string, (payload: unknown) => void>();

    return {
        listeners,
        listen(event, callback) {
            listeners.set(event, callback);
        },
        emit(event, payload) {
            listeners.get(event)?.(payload);
        },
    };
}

describe('bindApprovalEvents', () => {
    it('bumps the header badge on approval_pending notifications only', () => {
        const channel = fakeChannel();
        const notifications = useNotificationsStore();

        bindApprovalEvents(channel);

        channel.emit('notification.created', { id: 1, type: 'approval_pending' });
        expect(notifications.unreadCount).toBe(1);

        channel.emit('notification.created', { id: 2, type: 'approval_pending' });
        expect(notifications.unreadCount).toBe(2);

        // Other notification kinds never touch the approval badge.
        channel.emit('notification.created', { id: 3, type: 'task_completed' });
        expect(notifications.unreadCount).toBe(2);

        channel.emit('notification.created', {});
        expect(notifications.unreadCount).toBe(2);
    });
});
