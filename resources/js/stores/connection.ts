import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import type { ConnectionTransport } from '@/lib/echo';

export type ConnectionState = 'connected' | 'connecting' | 'disconnected';

/** Silence tolerated before the socket is considered dead. */
export const HEARTBEAT_TIMEOUT_MS = 5000;
export const WATCHDOG_INTERVAL_MS = 500;
export const RECONNECT_BASE_DELAY_MS = 1000;
export const RECONNECT_MAX_DELAY_MS = 30000;

/** Exponential backoff: 1 s, 2 s, 4 s, ... capped at 30 s. */
export function reconnectDelay(attempt: number): number {
    return Math.min(RECONNECT_MAX_DELAY_MS, RECONNECT_BASE_DELAY_MS * 2 ** attempt);
}

function mapPusherState(state: string): ConnectionState {
    switch (state) {
        case 'connected':
            return 'connected';
        case 'initialized':
        case 'connecting':
            return 'connecting';
        default:
            // unavailable, failed, disconnected
            return 'disconnected';
    }
}

export const useConnectionStore = defineStore('connection', () => {
    const state = ref<ConnectionState>('connecting');
    const reconnectAttempt = ref(0);
    const nextRetryDelayMs = ref<number | null>(null);
    const lastHeartbeatAt = ref<number>(Date.now());

    let transport: ConnectionTransport | null = null;
    let watchdogTimer: ReturnType<typeof setInterval> | null = null;
    let reconnectTimer: ReturnType<typeof setTimeout> | null = null;

    const isConnected = computed(() => state.value === 'connected');
    const isDisconnected = computed(() => state.value === 'disconnected');

    function isBrowserOnline(): boolean {
        return typeof navigator === 'undefined' || navigator.onLine !== false;
    }

    function clearReconnect(): void {
        if (reconnectTimer !== null) {
            clearTimeout(reconnectTimer);
            reconnectTimer = null;
        }

        nextRetryDelayMs.value = null;
    }

    function scheduleReconnect(): void {
        if (transport === null || reconnectTimer !== null || !isBrowserOnline()) {
            return;
        }

        const delay = reconnectDelay(reconnectAttempt.value);
        nextRetryDelayMs.value = delay;

        reconnectTimer = setTimeout(() => {
            reconnectTimer = null;
            nextRetryDelayMs.value = null;
            reconnectAttempt.value++;
            state.value = 'connecting';
            transport?.connect();
        }, delay);
    }

    function markConnected(): void {
        state.value = 'connected';
        lastHeartbeatAt.value = Date.now();
        reconnectAttempt.value = 0;
        clearReconnect();
    }

    function markDisconnected(): void {
        if (state.value !== 'disconnected') {
            state.value = 'disconnected';
        }

        scheduleReconnect();
    }

    function handleTransportState(raw: string): void {
        const mapped = mapPusherState(raw);

        if (mapped === 'connected') {
            markConnected();
        } else if (mapped === 'disconnected') {
            markDisconnected();
        } else {
            state.value = 'connecting';
        }
    }

    function heartbeat(): void {
        lastHeartbeatAt.value = Date.now();

        if (state.value !== 'connected' && transport !== null && reconnectTimer === null) {
            markConnected();
        }
    }

    function checkHeartbeat(): void {
        // Accounts for the tick granularity so silence is detected in at most HEARTBEAT_TIMEOUT_MS.
        const silence = Date.now() - lastHeartbeatAt.value;

        if (state.value === 'connected' && silence + WATCHDOG_INTERVAL_MS > HEARTBEAT_TIMEOUT_MS) {
            markDisconnected();
        }
    }

    function handleOffline(): void {
        clearReconnect();
        state.value = 'disconnected';
    }

    function handleOnline(): void {
        reconnectAttempt.value = 0;
        clearReconnect();
        state.value = 'connecting';
        transport?.connect();
    }

    /**
     * Starts monitoring a realtime transport (Echo/Pusher) plus `navigator.onLine`.
     */
    function start(connectionTransport: ConnectionTransport): void {
        stop();

        transport = connectionTransport;
        state.value = isBrowserOnline() ? 'connecting' : 'disconnected';
        lastHeartbeatAt.value = Date.now();

        transport.onStateChange(handleTransportState);
        transport.onActivity(heartbeat);

        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);

        watchdogTimer = setInterval(checkHeartbeat, WATCHDOG_INTERVAL_MS);
    }

    function stop(): void {
        clearReconnect();

        if (watchdogTimer !== null) {
            clearInterval(watchdogTimer);
            watchdogTimer = null;
        }

        window.removeEventListener('online', handleOnline);
        window.removeEventListener('offline', handleOffline);

        transport = null;
        reconnectAttempt.value = 0;
        state.value = 'connecting';
    }

    return {
        state,
        reconnectAttempt,
        nextRetryDelayMs,
        lastHeartbeatAt,
        isConnected,
        isDisconnected,
        start,
        stop,
        markDisconnected,
        markConnected,
    };
});
