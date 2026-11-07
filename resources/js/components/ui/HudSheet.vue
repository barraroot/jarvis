<script setup lang="ts">
import { computed, onBeforeUnmount, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useReducedMotion } from '@/composables/useReducedMotion';

/**
 * Mobile bottom sheet (UI-23): slides up from the bottom in `--dur-base`,
 * half screen by default and full height when `expanded`; the 44 px handle
 * toggles the height and `Esc` closes it. Reduced motion shows it at once (RNF-01).
 */
withDefaults(
    defineProps<{
        label?: string;
    }>(),
    { label: undefined },
);

const open = defineModel<boolean>('open', { default: false });
const expanded = defineModel<boolean>('expanded', { default: false });
const { t } = useI18n();
const reducedMotion = useReducedMotion();

const transition = computed(() => ({
    css: !reducedMotion.value,
    enterActiveClass: 'transition-transform duration-(--dur-base) ease-(--ease-fly)',
    enterFromClass: 'translate-y-full',
    leaveActiveClass: 'transition-transform duration-(--dur-base) ease-(--ease-fly)',
    leaveToClass: 'translate-y-full',
}));

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        open.value = false;
    }
}

watch(
    open,
    (isOpen) => {
        if (isOpen) {
            document.addEventListener('keydown', onKeydown);
        } else {
            document.removeEventListener('keydown', onKeydown);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Transition v-bind="transition">
        <section
            v-if="open"
            role="dialog"
            :aria-label="label"
            class="glass chamfer-md fixed inset-x-0 bottom-0 z-40 flex flex-col"
            :class="expanded ? 'top-14' : 'max-h-[60dvh]'"
            :data-expanded="expanded ? 'true' : 'false'"
            data-testid="hud-sheet"
        >
            <button
                type="button"
                class="flex min-h-11 w-full shrink-0 items-center justify-center"
                :aria-expanded="expanded"
                :aria-label="expanded ? t('layout.sheetCollapse') : t('layout.sheetExpand')"
                data-testid="sheet-handle"
                @click="expanded = !expanded"
            >
                <span class="h-1 w-10 bg-ocean-600" aria-hidden="true"></span>
            </button>
            <div class="min-h-0 flex-1 overflow-y-auto px-4 pb-4">
                <slot />
            </div>
        </section>
    </Transition>
</template>
