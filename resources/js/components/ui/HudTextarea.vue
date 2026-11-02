<script setup lang="ts">
import { useId } from 'vue';

withDefaults(
    defineProps<{
        label?: string;
        error?: string | null;
        id?: string;
        rows?: number;
        placeholder?: string;
        disabled?: boolean;
    }>(),
    { label: undefined, error: null, id: undefined, rows: 3, placeholder: undefined, disabled: false },
);

const model = defineModel<string>({ default: '' });
const generatedId = useId();
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1">
        <label v-if="label" :for="id ?? generatedId" class="hud-label text-[0.65rem] text-muted">{{ label }}</label>
        <textarea
            :id="id ?? generatedId"
            v-model="model"
            :rows="rows"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-invalid="error ? 'true' : undefined"
            class="w-full min-w-0 resize-y rounded-sm border bg-bg-navy/70 px-3 py-2 font-mono text-sm text-text placeholder:text-muted focus:border-cyan focus:outline-none disabled:opacity-50"
            :class="error ? 'border-danger' : 'border-line'"
        ></textarea>
        <p v-if="error" class="text-xs text-danger" role="alert">{{ error }}</p>
    </div>
</template>
