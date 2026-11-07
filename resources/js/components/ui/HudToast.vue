<script setup lang="ts">
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import { useToastStore, type ToastVariant } from '@/stores/toast';

const toastStore = useToastStore();
const { toasts } = storeToRefs(toastStore);
const { t } = useI18n();

/** Accent per variant (UI-25): approval amber, completion green, error red, others cyan. */
const variantClasses: Record<ToastVariant, string> = {
    info: 'border-l-cyan-300 text-biolum-200',
    success: 'border-l-success-400 text-success-400',
    warning: 'border-l-amber-400 text-amber-400',
    danger: 'border-l-danger-400 text-danger-400',
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
            class="glass chamfer-md pointer-events-auto flex items-start gap-3 border-l-2 px-4 py-3 text-sm"
            :class="[`toast--accent-${toast.variant}`, variantClasses[toast.variant]]"
            :data-variant="toast.variant"
            role="status"
        >
            <p class="min-w-0 flex-1 break-words">{{ toast.message }}</p>
            <button
                type="button"
                class="inline-flex size-11 shrink-0 items-center justify-center text-text-400 hover:text-text-100"
                :aria-label="t('common.close')"
                @click="toastStore.dismiss(toast.id)"
            >
                &times;
            </button>
        </div>
    </div>
</template>
