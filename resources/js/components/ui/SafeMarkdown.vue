<script setup lang="ts">
import { computed } from 'vue';
import { renderMarkdown } from '@/lib/markdown';

const props = defineProps<{
    /** Markdown source; raw HTML is escaped, never interpreted. */
    source: string;
}>();

const html = computed(() => renderMarkdown(props.source));
</script>

<template>
    <!-- The single v-html in the codebase; content is sanitized by renderMarkdown(). -->
    <div class="markdown-body min-w-0 break-words" data-testid="safe-markdown" v-html="html" />
</template>
