<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { usePushToTalk } from '@/composables/usePushToTalk';

/**
 * Mobile push-to-talk (RF-06, UI-23): an 84 px circle with an amber border.
 * Holding it wakes/unmutes, releasing mutes without sleeping. `keyboard`
 * also binds `Space` on the document (desktop/tablet).
 */
const props = withDefaults(
    defineProps<{
        keyboard?: boolean;
    }>(),
    { keyboard: false },
);

/** Same events as `AppHeader`: `wake` opens the window, `microphone` toggles the mute. */
const emit = defineEmits<{
    wake: [];
    microphone: [];
}>();

const { t } = useI18n();

const { pressed, available, press, release } = usePushToTalk({
    onWake: () => emit('wake'),
    onToggleMic: () => emit('microphone'),
    keyboard: props.keyboard,
});

function onPointerdown(event: PointerEvent): void {
    if (event.button > 0 || !available.value) {
        return;
    }

    const target = event.currentTarget as HTMLElement | null;

    try {
        target?.setPointerCapture?.(event.pointerId);
    } catch {
        // Synthetic or already-released pointers cannot be captured; release still arrives.
    }

    press();
}
</script>

<template>
    <button
        type="button"
        class="ptt inline-flex size-[84px] shrink-0 touch-none select-none items-center justify-center border-2 border-amber-400 bg-abyss-900 text-amber-400 transition-colors duration-(--dur-fast)"
        :class="[pressed ? 'bg-amber-400/20 glow' : '', available ? '' : 'cursor-not-allowed opacity-50']"
        :aria-label="t('ptt.hold')"
        :aria-pressed="pressed"
        :aria-disabled="available ? undefined : 'true'"
        :data-pressed="pressed ? 'true' : 'false'"
        data-testid="push-to-talk"
        @pointerdown="onPointerdown"
        @pointerup="release"
        @pointercancel="release"
        @contextmenu.prevent
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-8" aria-hidden="true">
            <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
            <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
            <line x1="12" x2="12" y1="19" y2="22" />
        </svg>
    </button>
</template>

<style scoped>
/* Circular by design (UI-23): the only control exempt from the chamfer rule besides the core. */
.ptt {
    border-radius: 50%;
}
</style>
