import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ConnectionTransport } from '@/lib/echo';
import { HEARTBEAT_TIMEOUT_MS, reconnectDelay, useConnectionStore } from '@/stores/connection';

function fakeTransport() {
    let stateListener: (state: string) => void = () => {};
    let activityListener: () => void = () => {};

    const transport: ConnectionTransport = {
        onStateChange: (listener) => {
            stateListener = listener;
        },
        onActivity: (listener) => {
            activityListener = listener;
        },
        connect: vi.fn(),
        disconnect: vi.fn(),
    };

    return {
        transport,
        emitState: (state: string) => stateListener(state),
        emitActivity: () => activityListener(),
    };
}

function setOnline(online: boolean): void {
    Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => online });
}

describe('connection store', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        setOnline(true);
    });

    afterEach(() => {
        useConnectionStore().stop();
    });

    it('maps pusher connection states', () => {
        const store = useConnectionStore();
        const { transport, emitState } = fakeTransport();
        store.start(transport);

        expect(store.state).toBe('connecting');
        emitState('connected');
        expect(store.state).toBe('connected');
        emitState('unavailable');
        expect(store.state).toBe('disconnected');
        emitState('connecting');
        expect(store.state).toBe('connecting');
        emitState('failed');
        expect(store.state).toBe('disconnected');
    });

    it('marks disconnected within 5 s without heartbeat', () => {
        const store = useConnectionStore();
        const { transport, emitState, emitActivity } = fakeTransport();
        store.start(transport);
        emitState('connected');

        vi.advanceTimersByTime(3000);
        emitActivity();
        vi.advanceTimersByTime(3000);
        expect(store.state).toBe('connected');

        // Last heartbeat was 3 s ago: the drop must be detected within the 5 s budget.
        let elapsedSinceHeartbeat = 3000;
        while (store.state === 'connected' && elapsedSinceHeartbeat < 10000) {
            vi.advanceTimersByTime(100);
            elapsedSinceHeartbeat += 100;
        }

        expect(store.state).toBe('disconnected');
        expect(elapsedSinceHeartbeat).toBeLessThanOrEqual(HEARTBEAT_TIMEOUT_MS);
    });

    it('reconnects with exponential backoff from 1 s up to 30 s', () => {
        expect([0, 1, 2, 3, 4, 5, 6, 10].map(reconnectDelay)).toEqual([
            1000, 2000, 4000, 8000, 16000, 30000, 30000, 30000,
        ]);

        const store = useConnectionStore();
        const { transport, emitState } = fakeTransport();
        store.start(transport);

        emitState('unavailable');
        expect(store.nextRetryDelayMs).toBe(1000);
        vi.advanceTimersByTime(999);
        expect(transport.connect).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(transport.connect).toHaveBeenCalledTimes(1);
        expect(store.state).toBe('connecting');

        emitState('unavailable');
        expect(store.nextRetryDelayMs).toBe(2000);
        vi.advanceTimersByTime(2000);
        expect(transport.connect).toHaveBeenCalledTimes(2);

        emitState('connected');
        expect(store.reconnectAttempt).toBe(0);
        expect(store.nextRetryDelayMs).toBeNull();
    });

    it('follows navigator online/offline events', () => {
        const store = useConnectionStore();
        const { transport, emitState } = fakeTransport();
        store.start(transport);
        emitState('connected');

        setOnline(false);
        window.dispatchEvent(new Event('offline'));
        expect(store.state).toBe('disconnected');
        expect(store.nextRetryDelayMs).toBeNull();

        setOnline(true);
        window.dispatchEvent(new Event('online'));
        expect(store.state).toBe('connecting');
        expect(transport.connect).toHaveBeenCalledTimes(1);
    });

    it('starts disconnected when the browser is offline', () => {
        setOnline(false);
        const store = useConnectionStore();

        store.start(fakeTransport().transport);

        expect(store.state).toBe('disconnected');
    });
});
