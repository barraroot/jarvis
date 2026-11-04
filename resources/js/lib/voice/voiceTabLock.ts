import { ref, type Ref } from 'vue';

/** Web Locks name owned by the tab that runs the voice (D5). */
export const VOICE_LOCK_NAME = 'jarvis-voice';

export interface VoiceTabLock {
    /** True while this tab owns the voice; other tabs stay text-only. */
    held: Ref<boolean>;
    release(): void;
}

interface LockManagerLike {
    request(name: string, options: { signal?: AbortSignal }, callback: () => Promise<void>): Promise<unknown>;
}

function lockManager(): LockManagerLike | null {
    const locks = (typeof navigator === 'undefined' ? undefined : (navigator as unknown as { locks?: LockManagerLike }).locks) ?? null;

    return locks !== null && typeof locks.request === 'function' ? locks : null;
}

/**
 * Only one HUD tab owns the voice (D5): the lock is requested through
 * `navigator.locks` and kept until the tab closes or {@link VoiceTabLock.release}
 * runs; the next waiting tab gets it automatically. Without Web Locks the
 * tab behaves as the only one.
 */
export function acquireVoiceTabLock(name: string = VOICE_LOCK_NAME): VoiceTabLock {
    const locks = lockManager();

    if (locks === null) {
        return { held: ref(true), release: () => undefined };
    }

    const held = ref(false);
    const controller = new AbortController();
    let releaseLock: (() => void) | null = null;

    locks
        .request(name, { signal: controller.signal }, () => {
            held.value = true;

            return new Promise<void>((resolve) => {
                releaseLock = resolve;
            });
        })
        .catch(() => undefined);

    return {
        held,
        release(): void {
            held.value = false;
            controller.abort();
            releaseLock?.();
            releaseLock = null;
        },
    };
}
