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
        class="inline-flex min-h-11 max-w-full items-center gap-3 text-left disabled:cursor-not-allowed disabled:opacity-50"
        @click="model = !model"
    >
        <span
            class="chamfer-sm relative inline-flex h-6 w-11 shrink-0 items-center border transition-colors duration-(--dur-base)"
            :class="model ? 'border-cyan-300 bg-cyan-300/30' : 'border-ocean-600 bg-abyss-900'"
        >
            <span
                class="chamfer-sm absolute size-4 transition-transform duration-(--dur-base)"
                :class="model ? 'translate-x-6 bg-biolum-200' : 'translate-x-1 bg-text-400'"
            ></span>
        </span>
        <span v-if="label" class="min-w-0 truncate text-sm text-text-100">{{ label }}</span>
        <span class="sr-only">{{ model ? t('common.on') : t('common.off') }}</span>
    </button>
</template>
