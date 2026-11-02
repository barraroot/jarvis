import { onBeforeUnmount, watch } from 'vue';
import {
    createEchoTransport,
    disposeEcho,
    getEcho,
    isEchoConfigured,
    leaveUserChannel,
    subscribeToUserChannel,
} from '@/lib/echo';
import { useAuthStore } from '@/stores/auth';
import { useConnectionStore } from '@/stores/connection';

/**
 * Opens the Reverb socket and the `private-user.{id}` channel while someone is signed in.
 */
export function useRealtimeConnection(): void {
    const auth = useAuthStore();
    const connection = useConnectionStore();

    const stopWatching = watch(
        () => auth.user?.id ?? null,
        (userId, previousUserId) => {
            if (previousUserId !== null && previousUserId !== undefined) {
                leaveUserChannel(previousUserId);
                connection.stop();
                disposeEcho();
            }

            if (userId === null || !isEchoConfigured()) {
                return;
            }

            const echo = getEcho();
            connection.start(createEchoTransport(echo));
            subscribeToUserChannel(userId);
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        stopWatching();
        connection.stop();
        disposeEcho();
    });
}
