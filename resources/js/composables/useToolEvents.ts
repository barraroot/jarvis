import { useToolsStore } from '@/stores/tools';
import type { ToolEventPayload, ToolState } from '@/types/models';

/** Echo-compatible channel; the real one comes from `useRealtimeConnection`. */
export interface MessageChannel {
    listen(event: string, callback: (payload: unknown) => void): unknown;
}

/**
 * Feeds the side panel "Ferramentas" section with real `tool.*` events
 * (US-8.2): started → active, completed → idle, failed → error. The
 * enriched CT-03 payload (task, display name, input summary, output size)
 * is kept on the row for the AGENTES timeline.
 */
export function bindToolEvents(channel: MessageChannel): void {
    const tools = useToolsStore();

    const apply = (payload: unknown, status: ToolState['status']): void => {
        const event = payload as Partial<ToolEventPayload> | null;
        const id = String(event?.execution_id ?? '');
        const name = typeof event?.tool === 'string' ? event.tool : '';

        if (id === '' || name === '') {
            return;
        }

        const enriched = typeof event?.display_name === 'string' ? (event as ToolEventPayload) : undefined;

        tools.upsert(enriched === undefined ? { id, name, status } : { id, name, status, event: enriched });
    };

    channel.listen('.tool.started', (payload: unknown) => apply(payload, 'active'));
    channel.listen('.tool.completed', (payload: unknown) => apply(payload, 'idle'));
    channel.listen('.tool.failed', (payload: unknown) => apply(payload, 'error'));
}
