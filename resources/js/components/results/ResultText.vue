<script setup lang="ts">
import SafeMarkdown from '@/components/ui/SafeMarkdown.vue';
import type { TextResultPayload } from '@/types/results';

defineProps<{
    payload: TextResultPayload;
}>();
</script>

<template>
    <SafeMarkdown :source="payload.text" data-testid="result-text" />
</template>
