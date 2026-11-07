<script setup lang="ts">
import { useReducedMotion } from '@/composables/useReducedMotion';
import type { VisualizationQuestionSection } from '@/types/models';

defineProps<{
    section: VisualizationQuestionSection;
}>();

const reducedMotion = useReducedMotion();
</script>

<template>
    <!-- A new headline morphs (crossfade) into the old one (UI-12); reduced motion swaps it at once. -->
    <div class="grid">
        <Transition name="viz-morph" :css="!reducedMotion">
            <h1
                :key="section.headline"
                class="glow col-start-1 row-start-1 text-h1 font-medium text-biolum-200"
                data-testid="viz-question-headline"
            >
                {{ section.headline }}
            </h1>
        </Transition>
    </div>
</template>

<style scoped>
.viz-morph-enter-active,
.viz-morph-leave-active {
    transition:
        opacity var(--dur-base) var(--ease-fly),
        transform var(--dur-base) var(--ease-fly);
}

.viz-morph-enter-from {
    opacity: 0;
    transform: translateY(6px);
}

.viz-morph-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}
</style>
