import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

const QUERY = '(prefers-reduced-motion: reduce)';

function currentPreference(): boolean {
    return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(QUERY).matches;
}

/**
 * Tracks `prefers-reduced-motion: reduce` (RNF-06): components drop their
 * animations and apply the final state at once while it is true.
 */
export function useReducedMotion(): Ref<boolean> {
    const reducedMotion = ref(currentPreference());
    let mediaQuery: MediaQueryList | null = null;

    function sync(): void {
        reducedMotion.value = mediaQuery?.matches ?? false;
    }

    onMounted(() => {
        if (typeof window.matchMedia !== 'function') {
            return;
        }

        mediaQuery = window.matchMedia(QUERY);
        sync();
        mediaQuery.addEventListener?.('change', sync);
    });

    onBeforeUnmount(() => {
        mediaQuery?.removeEventListener?.('change', sync);
    });

    return reducedMotion;
}
