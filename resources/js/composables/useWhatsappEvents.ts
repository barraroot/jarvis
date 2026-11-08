import { useWhatsappStore } from '@/stores/whatsapp';
import type { WhatsappSessionStatusChange } from '@/types/models';

/** Echo-compatible channel; the real one comes from `useRealtimeConnection`. */
export interface MessageChannel {
    listen(event: string, callback: (payload: unknown) => void): unknown;
}

/**
 * `whatsapp.session.status.changed` (CT-10, UI-03): keeps the session badge
 * current and hides the QR/pairing code once connected, without polling.
 */
export function bindWhatsappEvents(channel: MessageChannel): void {
    const whatsapp = useWhatsappStore();

    channel.listen('.whatsapp.session.status.changed', (payload: unknown) => {
        const change = payload as WhatsappSessionStatusChange | null;

        if (typeof change?.session_id !== 'number' || typeof change.status !== 'string') {
            return;
        }

        whatsapp.applyStatusChange({
            ...change,
            previous_status: change.previous_status ?? null,
            suspended: change.suspended === true,
            error: typeof change.error === 'string' ? change.error : null,
        });
    });
}
