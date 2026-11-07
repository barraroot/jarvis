<script setup lang="ts">
import HudPanel from '@/components/ui/HudPanel.vue';
import SafeMarkdown from '@/components/ui/SafeMarkdown.vue';
import type { DocumentResultPayload } from '@/types/results';

defineProps<{
    payload: DocumentResultPayload;
}>();
</script>

<template>
    <HudPanel :title="payload.title" tag="div" data-testid="result-document">
        <SafeMarkdown v-if="payload.format === 'markdown'" :source="payload.body" />
        <pre v-else class="whitespace-pre-wrap break-words font-mono text-xs text-text-100">{{ payload.body }}</pre>
    </HudPanel>
</template>
