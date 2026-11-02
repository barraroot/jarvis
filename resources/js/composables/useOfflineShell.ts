import { onBeforeUnmount, watch } from 'vue';
import { useRoute } from 'vue-router';
import { redirectToLogin } from '@/lib/navigation';
import { useAuthStore } from '@/stores/auth';
import { reconnectDelay, useConnectionStore } from '@/stores/connection';

/**
 * Offline PWA shell (US-2.5, US-2.4): while the boot probe (`/auth/me`) cannot
 * reach the server, the HUD stays DISCONNECTED (banner + sending blocked, no
 * simulated answers) and the probe is retried with backoff and on `online`.
 * Once it succeeds `useRealtimeConnection` opens the socket and the stores
 * restore; an expired session goes to the login page.
 */
export function useOfflineShell(): void {
    const auth = useAuthStore();
    const connection = useConnectionStore();
    const route = useRoute();

    let retryTimer: ReturnType<typeof setTimeout> | null = null;
    let attempt = 0;

    const isOfflineShell = (): boolean => auth.unreachable && auth.user === null;

    function clearRetry(): void {
        if (retryTimer !== null) {
            clearTimeout(retryTimer);
            retryTimer = null;
        }
    }

    function scheduleRetry(): void {
        clearRetry();

        if (!isOfflineShell()) {
            return;
        }

        const delay = reconnectDelay(attempt);
        connection.markServerUnreachable(delay);

        retryTimer = setTimeout(() => {
            retryTimer = null;
            attempt++;
            void probe();
        }, delay);
    }

    async function probe(): Promise<void> {
        clearRetry();

        try {
            await auth.fetchMe();
        } catch {
            // Still unreachable: keep the shell and try again later.
        }

        if (isOfflineShell()) {
            scheduleRetry();

            return;
        }

        attempt = 0;
        connection.markServerReachable();

        if (auth.user === null && route.meta.requiresAuth) {
            await redirectToLogin();
        }
    }

    function onOnline(): void {
        if (isOfflineShell()) {
            attempt = 0;
            void probe();
        }
    }

    const stopWatching = watch(
        isOfflineShell,
        (offline) => {
            if (offline) {
                attempt = 0;
                scheduleRetry();
            } else {
                clearRetry();
                connection.markServerReachable();
            }
        },
        { immediate: true },
    );

    window.addEventListener('online', onOnline);

    onBeforeUnmount(() => {
        stopWatching();
        clearRetry();
        window.removeEventListener('online', onOnline);
    });
}
