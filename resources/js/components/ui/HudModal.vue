<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue';
import { useI18n } from 'vue-i18n';

withDefaults(
    defineProps<{
        title?: string;
    }>(),
    { title: undefined },
);

const open = defineModel<boolean>('open', { default: false });
const { t } = useI18n();

function close(): void {
    open.value = false;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        close();
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
    <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center p-3 sm:items-center" data-testid="hud-modal">
        <div class="absolute inset-0 bg-abyss-950/80 backdrop-blur-sm" data-testid="hud-modal-backdrop" @click="close"></div>
        <div
            role="dialog"
            aria-modal="true"
            :aria-label="title"
            class="glass chamfer-md relative flex max-h-[90dvh] w-full min-w-0 max-w-lg flex-col overflow-y-auto p-4 sm:p-5"
        >
            <header class="mb-4 flex items-center justify-between gap-3 border-b border-ocean-600/70 pb-2">
                <h2 v-if="title" class="label-caps truncate text-sm text-cyan-300 glow">{{ title }}</h2>
                <button
                    type="button"
                    class="ml-auto inline-flex size-11 shrink-0 items-center justify-center text-lg text-text-400 hover:text-biolum-200"
                    :aria-label="t('common.close')"
                    data-testid="hud-modal-close"
                    @click="close"
                >
                    &times;
                </button>
            </header>
            <slot />
            <footer v-if="$slots.footer" class="mt-5 flex flex-wrap justify-end gap-2">
                <slot name="footer" />
            </footer>
        </div>
    </div>
</template>
