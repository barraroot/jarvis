import { computed, onBeforeUnmount, onMounted, readonly, ref, type ComputedRef, type Ref } from 'vue';
import { useVoiceStore } from '@/stores/voice';

export interface PushToTalkOptions {
    /** Opens the listening window, same as `Acordar` (RF-05). */
    onWake: () => void;
    /** Toggles the microphone mute, same as `Mutar microfone` (RF-06). */
    onToggleMic: () => void;
    /** Holds `Space` on the document as push-to-talk (desktop/tablet). */
    keyboard?: boolean;
}

export interface PushToTalk {
    /** True while the button or `Space` is held. */
    pressed: Readonly<Ref<boolean>>;
    /** False while voice runs text-only: pressing does nothing. */
    available: ComputedRef<boolean>;
    press: () => void;
    release: () => void;
}

const EDITABLE_SELECTOR = 'input, textarea, select, [contenteditable]:not([contenteditable="false"])';

/** `Space` typed into a form field or editable region is text, never push-to-talk. */
function isEditableTarget(target: EventTarget | null): boolean {
    if (!(target instanceof Element)) {
        return false;
    }

    return (target instanceof HTMLElement && target.isContentEditable) || target.closest(EDITABLE_SELECTOR) !== null;
}

function isSpace(event: KeyboardEvent): boolean {
    return event.code === 'Space' || event.key === ' ';
}

/**
 * Push-to-talk (RF-06) over the existing voice actions, reading the voice
 * store at the instant of each event: pressing wakes a sleeping window (and
 * unmutes) or only unmutes an open one — never interrupting JARVIS' speech;
 * releasing mutes the microphone and keeps the window open (never sleeps).
 */
export function usePushToTalk(options: PushToTalkOptions): PushToTalk {
    const voice = useVoiceStore();
    const pressed = ref(false);
    const available = computed(() => voice.available);

    function press(): void {
        if (!voice.available || pressed.value) {
            return;
        }

        pressed.value = true;

        if (voice.phase === 'sleeping') {
            options.onWake();
        }

        if (voice.muted) {
            options.onToggleMic();
        }
    }

    function release(): void {
        if (!pressed.value) {
            return;
        }

        pressed.value = false;

        if (voice.available && !voice.muted) {
            options.onToggleMic();
        }
    }

    function onKeydown(event: KeyboardEvent): void {
        if (!isSpace(event) || event.repeat || pressed.value || !voice.available || isEditableTarget(event.target)) {
            return;
        }

        event.preventDefault();
        press();
    }

    function onKeyup(event: KeyboardEvent): void {
        if (!isSpace(event) || !pressed.value) {
            return;
        }

        event.preventDefault();
        release();
    }

    if (options.keyboard === true) {
        onMounted(() => {
            document.addEventListener('keydown', onKeydown);
            document.addEventListener('keyup', onKeyup);
            window.addEventListener('blur', release);
        });

        onBeforeUnmount(() => {
            document.removeEventListener('keydown', onKeydown);
            document.removeEventListener('keyup', onKeyup);
            window.removeEventListener('blur', release);
        });
    }

    return { pressed: readonly(pressed), available, press, release };
}
