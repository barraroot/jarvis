import { ref, watch, type Ref } from 'vue';

/**
 * Boolean ref mirrored to localStorage (falls back to memory when storage is unavailable).
 */
export function usePersistentFlag(key: string, defaultValue = false): Ref<boolean> {
    let initial = defaultValue;

    try {
        const stored = localStorage.getItem(key);

        if (stored !== null) {
            initial = stored === 'true';
        }
    } catch {
        // Storage disabled (private mode): keep the default.
    }

    const flag = ref(initial);

    watch(flag, (value) => {
        try {
            localStorage.setItem(key, String(value));
        } catch {
            // Ignore quota/availability errors; the in-memory state still works.
        }
    });

    return flag;
}
