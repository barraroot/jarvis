import { useConnectorsStore } from '@/stores/connectors';
import type { ConnectorStatusChange } from '@/types/models';

/** Echo-compatible channel; the real one comes from `useRealtimeConnection`. */
export interface MessageChannel {
    listen(event: string, callback: (payload: unknown) => void): unknown;
}

/**
 * `connector.status.changed` (US-9.4): keeps the connector list and the
 * header indicator ("n conectados / m com erro") current without polling.
 */
export function bindConnectorEvents(channel: MessageChannel): void {
    const connectors = useConnectorsStore();

    channel.listen('.connector.status.changed', (payload: unknown) => {
        const change = payload as ConnectorStatusChange | null;

        if (typeof change?.id !== 'number' || typeof change.status !== 'string') {
            return;
        }

        connectors.applyStatusChange(change);
    });
}
