import { describe, expect, it, vi } from 'vitest';
import { createEchoTransport, type ReverbEcho, userChannelName } from '@/lib/echo';

describe('echo', () => {
    it('targets the private-user.{id} channel', () => {
        expect(userChannelName(7)).toBe('user.7');
    });

    it('adapts the pusher connection to a transport', () => {
        const bindings: Record<string, (payload: unknown) => void> = {};
        const pusher = {
            connection: { bind: (event: string, callback: (payload: unknown) => void) => (bindings[event] = callback) },
            connect: vi.fn(),
            disconnect: vi.fn(),
        };
        const transport = createEchoTransport({ connector: { pusher } } as unknown as ReverbEcho);
        const states: string[] = [];
        let activity = 0;

        transport.onStateChange((state) => states.push(state));
        transport.onActivity(() => activity++);
        bindings.state_changed?.({ previous: 'connecting', current: 'connected' });
        bindings.message?.({ event: 'pusher:pong' });
        transport.connect();

        expect(states).toEqual(['connected']);
        expect(activity).toBe(1);
        expect(pusher.connect).toHaveBeenCalledOnce();
    });
});
