import { useVisualizationsStore } from '@/stores/visualizations';

/** Echo-compatible channel; the real one comes from `useRealtimeConnection`. */
export interface MessageChannel {
    listen(event: string, callback: (payload: unknown) => void): unknown;
}

interface VisualizationUpdatedPayload {
    visualization?: unknown;
}

/**
 * Feeds the VISUALIZAÇÃO panel with `visualization.updated` (CT-05): each
 * event carries the whole visualization and replaces the current snapshot.
 */
export function bindVisualizationEvents(channel: MessageChannel): void {
    const visualizations = useVisualizationsStore();

    channel.listen('.visualization.updated', (payload: unknown) => {
        visualizations.apply((payload as VisualizationUpdatedPayload | null)?.visualization);
    });
}
