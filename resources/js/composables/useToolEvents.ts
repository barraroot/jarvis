import { useToolsStore } from '@/stores/tools';
import type { ToolState } from '@/types/models';

interface ToolEventPayload {
    tool?: unknown;
    execution_id?: unknown;
    status?: unknown;
}

/** Echo-compatible channel; the real one comes from `useRealtimeConnection`. */
export interface MessageChannel {
    listen(event: string, callback: (payload: unknown) => void): unknown;
}

/**
 * Feeds the side panel "Ferramentas" section with real `tool.*` events
 * (US-8.2): started → active, completed → idle, failed → error.
 */
export function bindToolEvents(channel: MessageChannel): void {
    const tools = useToolsStore();

    const apply = (payload: unknown, status: ToolState['status']): void => {
        const event = payload as ToolEventPayload | null;
        const id = String(event?.execution_id ?? '');
        const name = typeof event?.tool === 'string' ? event.tool : '';

        if (id === '' || name === '') {
            return;
        }

        tools.upsert({ id, name, status });
    };

    channel.listen('.tool.started', (payload: unknown) => apply(payload, 'active'));
    channel.listen('.tool.completed', (payload: unknown) => apply(payload, 'idle'));
    channel.listen('.tool.failed', (payload: unknown) => apply(payload, 'error'));
}
