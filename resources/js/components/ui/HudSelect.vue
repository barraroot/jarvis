<script setup lang="ts">
import { useId } from 'vue';
import { useI18n } from 'vue-i18n';

export interface HudSelectOption {
    value: string;
    label: string;
    disabled?: boolean;
}

withDefaults(
    defineProps<{
        options: HudSelectOption[];
        label?: string;
        placeholder?: string;
        error?: string | null;
        id?: string;
        disabled?: boolean;
    }>(),
    { label: undefined, placeholder: undefined, error: null, id: undefined, disabled: false },
);

const model = defineModel<string>({ default: '' });
const generatedId = useId();
const { t } = useI18n();
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1">
        <label v-if="label" :for="id ?? generatedId" class="label-caps text-[0.65rem] text-text-400">{{ label }}</label>
        <select
            :id="id ?? generatedId"
            v-model="model"
            :disabled="disabled"
            :aria-invalid="error ? 'true' : undefined"
            class="w-full min-w-0 chamfer-sm min-h-11 border bg-abyss-900 px-3 py-2 font-mono text-sm text-text-100 transition-colors duration-(--dur-base) hover:border-ocean-400 focus:border-cyan-300 focus:outline-none disabled:opacity-50"
            :class="error ? 'border-danger-400' : 'border-ocean-600'"
        >
            <option value="" disabled>{{ placeholder ?? t('common.select') }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value" :disabled="option.disabled">
                {{ option.label }}
            </option>
        </select>
        <p v-if="error" class="text-xs text-danger-400" role="alert">{{ error }}</p>
    </div>
</template>
