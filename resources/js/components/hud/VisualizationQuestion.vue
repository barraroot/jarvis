<script setup lang="ts">
import type { VisualizationQuestionSection } from '@/types/models';

defineProps<{
    section: VisualizationQuestionSection;
}>();
</script>

<template>
    <p class="font-display text-lg leading-snug text-cyan-bright hud-glow sm:text-xl" data-testid="viz-question-headline">
        {{ section.headline }}
    </p>
</template>
