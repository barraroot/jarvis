import { onBeforeUnmount, watch } from 'vue';
import {
    createEchoTransport,
    disposeEcho,
    getEcho,
    isEchoConfigured,
    leaveUserChannel,
    subscribeToUserChannel,
} from '@/lib/echo';
import { useAssistantStore } from '@/stores/assistant';
import { useAuthStore } from '@/stores/auth';
import { useConnectionStore } from '@/stores/connection';
import { useMessagesStore } from '@/stores/messages';

/**
 * Opens the Reverb socket and the `private-user.{id}` channel while someone is signed in.
 */
export function useRealtimeConnection(): void {
    const auth = useAuthStore();
    const connection = useConnectionStore();
    const assistant = useAssistantStore();
    const messages = useMessagesStore();

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
            const channel = subscribeToUserChannel(userId);
            channel.listen('assistant.state.changed', (event: unknown) => {
                const payload = (event as { state?: unknown } | null)?.state;

                assistant.applyServerState(payload);
            });
            messages.bind(channel);
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        stopWatching();
        connection.stop();
        disposeEcho();
    });
}
