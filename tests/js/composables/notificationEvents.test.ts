import { describe, expect, it, vi } from 'vitest';
import { bindNotificationEvents, type MessageChannel } from '@/composables/useNotificationEvents';
import { useNotificationsStore } from '@/stores/notifications';
import { usePreferencesStore } from '@/stores/preferences';
import { useToastStore } from '@/stores/toast';

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

function payload(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id: '9',
        type: 'task_completed',
        title: 'Tarefa concluída',
        body: 'A tarefa terminou.',
        data: { task_id: 3 },
        read_at: null,
        created_at: '2026-11-02T12:00:00+00:00',
        ...overrides,
    };
}

describe('bindNotificationEvents', () => {
    it('bumps the badge and prepends the item on notification.created', () => {
        const channel = fakeChannel();
        const notifications = useNotificationsStore();

        notifications.clear();

        bindNotificationEvents(channel);

        channel.emit('notification.created', payload());
        expect(notifications.unreadCount).toBe(1);
        expect(notifications.items).toHaveLength(1);
        expect(notifications.items[0]?.data).toEqual({ task_id: 3 });

        // Read notifications arrive without bumping the counter.
        channel.emit('notification.created', payload({ id: '10', read_at: '2026-11-02T12:01:00+00:00' }));
        expect(notifications.unreadCount).toBe(1);
        expect(notifications.items).toHaveLength(2);
    });

    it('shows a toast by default and suppresses it with extra.toasts=false', () => {
        const channel = fakeChannel();
        const preferences = usePreferencesStore();
        const toasts = useToastStore();

        bindNotificationEvents(channel);

        channel.emit('notification.created', payload());
        expect(toasts.toasts).toHaveLength(1);
        expect(toasts.toasts[0]?.message).toBe('A tarefa terminou.');

        preferences.preference = {
            locale: 'pt-BR',
            speech_locale: 'pt-BR',
            voice_name: null,
            speech_rate: 1,
            spoken_responses_enabled: true,
            wake_word_enabled: false,
            wake_word_consented_at: null,
            silence_timeout_ms: 1500,
            extra: { toasts: false },
        };

        channel.emit('notification.created', payload({ id: '11' }));
        expect(toasts.toasts).toHaveLength(1);
    });

    it('ignores malformed payloads', () => {
        const channel = fakeChannel();
        const notifications = useNotificationsStore();

        bindNotificationEvents(channel);

        channel.emit('notification.created', {});
        channel.emit('notification.created', { id: 5 });

        expect(notifications.unreadCount).toBe(0);
        expect(notifications.items).toHaveLength(0);
    });
});
