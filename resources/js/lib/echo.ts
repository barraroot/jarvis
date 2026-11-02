import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import type { ChannelAuthorizationData } from 'pusher-js/types/src/core/auth/options';
import { http } from '@/lib/http';

export const BROADCASTING_AUTH_URL = '/broadcasting/auth';

/** Pusher protocol: ping after 2 s of silence, give up on a missing pong after 2.5 s. */
const ACTIVITY_TIMEOUT_MS = 2000;
const PONG_TIMEOUT_MS = 2500;

export type ReverbEcho = Echo<'reverb'>;

/**
 * Connection transport consumed by the connection store, decoupled from Pusher for testing.
 */
export interface ConnectionTransport {
    onStateChange(listener: (state: string) => void): void;
    onActivity(listener: () => void): void;
    connect(): void;
    disconnect(): void;
}

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

export function isEchoConfigured(): boolean {
    return Boolean(import.meta.env.VITE_REVERB_APP_KEY);
}

/**
 * Laravel Echo over Reverb; private channels authorize through `/broadcasting/auth`
 * with the Sanctum session cookie and XSRF header (via the shared HTTP client).
 */
export function createEcho(): ReverbEcho {
    window.Pusher = Pusher;

    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80));

    return new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        activityTimeout: ACTIVITY_TIMEOUT_MS,
        pongTimeout: PONG_TIMEOUT_MS,
        channelAuthorization: {
            endpoint: BROADCASTING_AUTH_URL,
            transport: 'ajax',
            customHandler: ({ socketId, channelName }, callback) => {
                http.post<ChannelAuthorizationData>(BROADCASTING_AUTH_URL, {
                    socket_id: socketId,
                    channel_name: channelName,
                }).then(
                    (data) => callback(null, data),
                    (error: unknown) => callback(error instanceof Error ? error : new Error(String(error)), null),
                );
            },
        },
    });
}

let echoInstance: ReverbEcho | null = null;

export function getEcho(): ReverbEcho {
    echoInstance ??= createEcho();

    return echoInstance;
}

export function disposeEcho(): void {
    echoInstance?.disconnect();
    echoInstance = null;
}

/** Private channel `private-user.{id}` of the authenticated operator. */
export function userChannelName(userId: number): string {
    return `user.${userId}`;
}

export function subscribeToUserChannel(userId: number) {
    return getEcho().private(userChannelName(userId));
}

export function leaveUserChannel(userId: number): void {
    echoInstance?.leave(userChannelName(userId));
}

/**
 * Adapts the underlying Pusher connection to a ConnectionTransport.
 */
export function createEchoTransport(echo: ReverbEcho): ConnectionTransport {
    const pusher = echo.connector.pusher;

    return {
        onStateChange: (listener) =>
            pusher.connection.bind('state_changed', (states: { current: string }) => listener(states.current)),
        // Any frame (events, pong) proves the socket is alive.
        onActivity: (listener) => pusher.connection.bind('message', () => listener()),
        connect: () => pusher.connect(),
        disconnect: () => pusher.disconnect(),
    };
}
