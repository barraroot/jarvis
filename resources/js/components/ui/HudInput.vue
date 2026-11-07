<script setup lang="ts">
import { useId } from 'vue';

withDefaults(
    defineProps<{
        label?: string;
        type?: string;
        error?: string | null;
        id?: string;
        autocomplete?: string;
        placeholder?: string;
        disabled?: boolean;
        required?: boolean;
    }>(),
    {
        label: undefined,
        type: 'text',
        error: null,
        id: undefined,
        autocomplete: undefined,
        placeholder: undefined,
        disabled: false,
        required: false,
    },
);

const model = defineModel<string>({ default: '' });
const generatedId = useId();
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1">
        <label v-if="label" :for="id ?? generatedId" class="label-caps text-[0.65rem] text-text-400">{{ label }}</label>
        <input
            :id="id ?? generatedId"
            v-model="model"
            :type="type"
            :autocomplete="autocomplete"
            :placeholder="placeholder"
            :disabled="disabled"
            :required="required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error ? `${id ?? generatedId}-error` : undefined"
            class="w-full min-w-0 chamfer-sm min-h-11 border bg-abyss-900/70 px-3 py-2 font-mono text-sm text-text-100 transition-colors duration-(--dur-base) placeholder:text-text-400 hover:border-ocean-400 focus:border-cyan-300 focus:outline-none disabled:opacity-50"
            :class="error ? 'border-danger-400' : 'border-ocean-600'"
        />
        <p v-if="error" :id="`${id ?? generatedId}-error`" class="text-xs text-danger-400" role="alert">{{ error }}</p>
    </div>
</template>
