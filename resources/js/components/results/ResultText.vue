<script setup lang="ts">
import SafeMarkdown from '@/components/ui/SafeMarkdown.vue';
import type { TextResultPayload } from '@/types/results';

defineProps<{
    payload: TextResultPayload;
}>();
</script>

<template>
    <div class="glass chamfer-md min-w-0 px-3 py-2" data-testid="result-text">
        <SafeMarkdown :source="payload.text" />
    </div>
</template>
