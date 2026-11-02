import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import type { AssistantState } from '@/types/models';
import { useConnectionStore } from '@/stores/connection';

export const ASSISTANT_STATES: readonly AssistantState[] = [
    'IDLE',
    'LISTENING',
    'THINKING',
    'SPEAKING',
    'EXECUTING',
    'SLEEPING',
    'ERROR',
    'DISCONNECTED',
];

export function isAssistantState(value: unknown): value is AssistantState {
    return typeof value === 'string' && (ASSISTANT_STATES as readonly string[]).includes(value);
}

/**
 * State machine behind the JarvisCore.
 *
 * Priorities:
 * - DISCONNECTED (from the connection store) overrides everything while the realtime link is down.
 * - SLEEPING only exits through wake() — transitions are ignored while sleeping.
 * - Invalid transitions are ignored and logged in dev.
 *
 * Phase 7 feeds `assistant.state.changed` events through `applyServerState()`; local voice
 * interactions (Phase 9) call `setState('LISTENING' | 'SPEAKING')` directly.
 */
export const useAssistantStore = defineStore('assistant', () => {
    /** State requested by the app (voice, events, user actions). */
    const desiredState = ref<AssistantState>('IDLE');
    const audioLevel = ref(0);

    const state = computed<AssistantState>(() => {
        if (useConnectionStore().isDisconnected) {
            return 'DISCONNECTED';
        }

        return desiredState.value;
    });

    function logIgnoredTransition(next: AssistantState, reason: string): void {
        if (import.meta.env.DEV) {
            console.warn(`[assistant] ignored transition to ${next}: ${reason}`);
        }
    }

    function setState(next: AssistantState): void {
        if (next === desiredState.value) {
            return;
        }

        if (useConnectionStore().isDisconnected) {
            logIgnoredTransition(next, 'disconnected');

            return;
        }

        if (desiredState.value === 'SLEEPING') {
            logIgnoredTransition(next, 'sleeping');

            return;
        }

        desiredState.value = next;
    }

    function sleep(): void {
        if (desiredState.value !== 'SLEEPING') {
            desiredState.value = 'SLEEPING';
        }
    }

    /** SLEEPING is the only state that requires an explicit wake. */
    function wake(): void {
        if (desiredState.value === 'SLEEPING') {
            desiredState.value = 'IDLE';
        }
    }

    function setAudioLevel(level: number): void {
        audioLevel.value = Number.isFinite(level) ? Math.min(1, Math.max(0, level)) : 0;
    }

    /**
     * Applies a state received from `assistant.state.changed` (Phase 7). Unknown
     * payloads are ignored so a malformed event cannot break the core.
     */
    function applyServerState(value: unknown): void {
        if (isAssistantState(value)) {
            setState(value);
        }
    }

    return { state, desiredState, audioLevel, setState, sleep, wake, setAudioLevel, applyServerState };
});
