import { useActivityStore } from '@/stores/activity';
import { useAgentsStore } from '@/stores/agents';

/** Echo-compatible channel; the real one comes from `useRealtimeConnection`. */
export interface MessageChannel {
    listen(event: string, callback: (payload: unknown) => void): unknown;
}

/**
 * Feeds the side panel with real `agent.*` events (US-7.4): started moves the
 * run into "Agentes ativos", progress updates its note, and completed/failed
 * settle it into the history with the measured duration. The terminal feed
 * also gets one line per event. No polling.
 */
export function bindAgentEvents(channel: MessageChannel): void {
    const agents = useAgentsStore();
    const activity = useActivityStore();

    const log = (payload: unknown, verb: string): void => {
        const line = entry(payload, verb);

        if (line !== null) {
            activity.push(line);
        }
    };

    channel.listen('agent.started', (payload: unknown) => {
        agents.start(payload);
        log(payload, 'iniciou');
    });
    channel.listen('agent.progress', (payload: unknown) => {
        agents.progress(payload);
    });
    channel.listen('agent.completed', (payload: unknown) => {
        agents.finish(payload);
        log(payload, 'concluído');
    });
    channel.listen('agent.failed', (payload: unknown) => {
        agents.finish(payload);
        log(payload, 'falhou');
    });
}

function entry(payload: unknown, verb: string): { id: string; kind: string; text: string; timestamp: string } | null {
    const event = payload as { execution_id?: unknown; agent?: unknown; depth?: unknown } | null;
    const executionId = Number(event?.execution_id);

    if (!Number.isInteger(executionId) || executionId <= 0) {
        return null;
    }

    const agent = typeof event?.agent === 'string' ? event.agent : 'agente';
    const depth = Number(event?.depth ?? 0);

    return {
        id: `agent:${executionId}`,
        kind: 'agent',
        text: `Agente ${agent} ${verb}${depth > 0 ? ` (profundidade ${depth})` : ''}`,
        timestamp: new Date().toISOString(),
    };
}

