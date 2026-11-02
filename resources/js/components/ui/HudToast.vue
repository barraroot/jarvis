<script setup lang="ts">
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import { useToastStore, type ToastVariant } from '@/stores/toast';

const toastStore = useToastStore();
const { toasts } = storeToRefs(toastStore);
const { t } = useI18n();

const variantClasses: Record<ToastVariant, string> = {
    info: 'border-cyan/60 text-cyan-bright',
    success: 'border-cyan text-cyan-bright',
    warning: 'border-amber/70 text-amber',
    danger: 'border-danger/70 text-danger',
};
</script>

<template>
    <div
        class="pointer-events-none fixed inset-x-3 top-3 z-[60] flex flex-col items-stretch gap-2 sm:inset-x-auto sm:right-4 sm:w-96"
        aria-live="polite"
    >
        <div
            v-for="toast in toasts"
            :key="toast.id"
            class="hud-glass pointer-events-auto flex items-start gap-3 rounded-sm border bg-bg-navy/90 px-4 py-3 text-sm"
            :class="variantClasses[toast.variant]"
            :data-variant="toast.variant"
            role="status"
        >
            <p class="min-w-0 flex-1 break-words">{{ toast.message }}</p>
            <button
                type="button"
                class="shrink-0 text-muted hover:text-text"
                :aria-label="t('common.close')"
                @click="toastStore.dismiss(toast.id)"
            >
                &times;
            </button>
        </div>
    </div>
</template>
