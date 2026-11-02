<script setup lang="ts">
import { useI18n } from 'vue-i18n';

withDefaults(
    defineProps<{
        label?: string;
        disabled?: boolean;
    }>(),
    { label: undefined, disabled: false },
);

const model = defineModel<boolean>({ default: false });
const { t } = useI18n();
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="model"
        :aria-label="label"
        :disabled="disabled"
        class="inline-flex max-w-full items-center gap-3 text-left disabled:cursor-not-allowed disabled:opacity-50"
        @click="model = !model"
    >
        <span
            class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full border transition-colors"
            :class="model ? 'border-cyan bg-cyan/30' : 'border-line bg-bg-navy'"
        >
            <span
                class="absolute size-3.5 rounded-full transition-transform"
                :class="model ? 'translate-x-4.5 bg-cyan-bright' : 'translate-x-0.5 bg-muted'"
            ></span>
        </span>
        <span v-if="label" class="min-w-0 truncate text-sm text-text">{{ label }}</span>
        <span class="sr-only">{{ model ? t('common.on') : t('common.off') }}</span>
    </button>
</template>
