<script setup lang="ts">
import { nextTick, onMounted, ref, useId, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        label?: string;
        error?: string | null;
        id?: string;
        rows?: number;
        placeholder?: string;
        disabled?: boolean;
        maxlength?: number;
        /** Grows with the content instead of resizing manually. */
        autoExpand?: boolean;
        /** Row cap when auto-expanding. */
        maxRows?: number;
    }>(),
    {
        label: undefined,
        error: null,
        id: undefined,
        rows: 3,
        placeholder: undefined,
        disabled: false,
        maxlength: undefined,
        autoExpand: false,
        maxRows: 6,
    },
);

const model = defineModel<string>({ default: '' });
const generatedId = useId();
const textareaRef = ref<HTMLTextAreaElement | null>(null);

function resizeToContent(): void {
    const element = textareaRef.value;

    if (element === null || !props.autoExpand) {
        return;
    }

    element.style.height = 'auto';

    const lineHeight = Number.parseFloat(window.getComputedStyle(element).lineHeight || '20');
    const maxHeight = Math.max(lineHeight, lineHeight * props.maxRows);
    const contentHeight = element.scrollHeight || lineHeight;

    element.style.height = `${Math.min(contentHeight, maxHeight)}px`;
    element.style.overflowY = contentHeight > maxHeight ? 'auto' : 'hidden';
}

watch(model, async () => {
    await nextTick();
    resizeToContent();
});

onMounted(resizeToContent);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1">
        <label v-if="label" :for="id ?? generatedId" class="hud-label text-[0.65rem] text-muted">{{ label }}</label>
        <textarea
            ref="textareaRef"
            :id="id ?? generatedId"
            v-model="model"
            :rows="autoExpand ? 1 : rows"
            :placeholder="placeholder"
            :disabled="disabled"
            :maxlength="maxlength"
            :aria-invalid="error ? 'true' : undefined"
            class="w-full min-w-0 rounded-sm border bg-bg-navy/70 px-3 py-2 font-mono text-sm text-text placeholder:text-muted focus:border-cyan focus:outline-none disabled:opacity-50"
            :class="[error ? 'border-danger' : 'border-line', autoExpand ? 'resize-none overflow-hidden' : 'resize-y']"
            @input="resizeToContent"
        ></textarea>
        <p v-if="error" class="text-xs text-danger" role="alert">{{ error }}</p>
    </div>
</template>
