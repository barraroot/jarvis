<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConnectionStore } from '@/stores/connection';

/** `Conectado`, `Reconectando…` or `Offline` (UI-04). */
type ConnectionIndicatorState = 'connected' | 'reconnecting' | 'offline';

const { t } = useI18n();
const connection = useConnectionStore();

/** Precedence: offline > reconnecting > connected (UI-04). */
const indicatorState = computed<ConnectionIndicatorState>(() => {
    if (connection.isDisconnected) {
        return 'offline';
    }

    if (connection.state === 'connecting' || connection.nextRetryDelayMs !== null) {
        return 'reconnecting';
    }

    return 'connected';
});

const dotClasses: Record<ConnectionIndicatorState, string> = {
    connected: 'bg-cyan-300 shadow-[0_0_8px_var(--color-cyan-300)]',
    reconnecting: 'connection-indicator__dot--pulse bg-amber-400 shadow-[0_0_8px_var(--color-amber-400)]',
    offline: 'bg-danger-400',
};
</script>

<template>
    <span
        class="connection-indicator inline-flex shrink-0 items-center gap-1.5 font-mono text-[11px] font-medium text-text-400"
        :class="`connection-indicator--${indicatorState}`"
        role="status"
        aria-live="polite"
        :data-state="indicatorState"
        data-testid="connection-indicator"
    >
        <span class="connection-indicator__dot size-2 shrink-0" :class="dotClasses[indicatorState]" aria-hidden="true" data-dot></span>
        <span data-testid="connection-indicator-label">{{ t(`header.connection.${indicatorState}`) }}</span>
    </span>
</template>

<style scoped>
.connection-indicator__dot {
    border-radius: 50%;
}

.connection-indicator__dot--pulse {
    animation: connection-indicator-pulse 1s ease-in-out infinite;
}

@keyframes connection-indicator-pulse {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.35;
    }
}

@media (prefers-reduced-motion: reduce) {
    .connection-indicator__dot--pulse {
        animation: none;
    }
}
</style>
