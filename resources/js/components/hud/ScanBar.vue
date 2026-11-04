<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useReducedMotion } from '@/composables/useReducedMotion';

const { t } = useI18n();
const reducedMotion = useReducedMotion();
</script>

<template>
    <div
        class="relative h-1 w-full overflow-hidden rounded-full bg-line/50"
        role="progressbar"
        :aria-label="t('visualizations.scanning')"
        data-testid="scan-bar"
    >
        <span
            class="scan-bar__beam absolute inset-y-0 left-0 w-1/3 bg-linear-to-r from-transparent via-cyan-bright to-transparent"
            :class="{ 'scan-bar__beam--animated': !reducedMotion }"
        ></span>
    </div>
</template>

<style scoped>
.scan-bar__beam--animated {
    animation: scan-bar-sweep 0.7s ease-in-out infinite;
}

@keyframes scan-bar-sweep {
    from {
        transform: translateX(-100%);
    }

    to {
        transform: translateX(300%);
    }
}
</style>
