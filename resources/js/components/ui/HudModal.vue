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
        <div class="absolute inset-0 bg-bg/80 backdrop-blur-sm" data-testid="hud-modal-backdrop" @click="close"></div>
        <div
            role="dialog"
            aria-modal="true"
            :aria-label="title"
            class="hud-border hud-glass relative flex max-h-[90dvh] w-full min-w-0 max-w-lg flex-col overflow-y-auto rounded-sm bg-bg-navy/90 p-4 sm:p-5"
        >
            <header class="mb-4 flex items-center justify-between gap-3 border-b border-line/70 pb-2">
                <h2 v-if="title" class="hud-label truncate text-sm text-cyan hud-glow">{{ title }}</h2>
                <button
                    type="button"
                    class="ml-auto inline-flex size-11 shrink-0 items-center justify-center text-lg text-muted hover:text-cyan-bright"
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
