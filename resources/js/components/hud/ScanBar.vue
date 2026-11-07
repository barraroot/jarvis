<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useReducedMotion } from '@/composables/useReducedMotion';

withDefaults(
    defineProps<{
        /**
         * `progress` = thin bar before a visualization section (UI-12);
         * `sweep` = one horizontal beam crossing the stage top to bottom, the
         * first step of the signature transition (UI-06). Decorative only.
         */
        variant?: 'progress' | 'sweep';
    }>(),
    { variant: 'progress' },
);

const { t } = useI18n();
const reducedMotion = useReducedMotion();
</script>

<template>
    <div
        v-if="variant === 'sweep'"
        class="pointer-events-none absolute inset-0 z-20 overflow-hidden"
        aria-hidden="true"
        data-testid="scan-sweep"
    >
        <span
            class="scan-bar__sweep absolute inset-x-0 top-0 h-full border-b-2 border-cyan-300/80"
            :class="{ 'scan-bar__sweep--animated': !reducedMotion }"
        ></span>
    </div>
    <div
        v-else
        class="relative h-1 w-full overflow-hidden bg-ocean-600/50"
        role="progressbar"
        :aria-label="t('visualizations.scanning')"
        data-testid="scan-bar"
    >
        <span
            class="scan-bar__beam absolute inset-y-0 left-0 w-1/3 bg-linear-to-r from-transparent via-biolum-200 to-transparent"
            :class="{ 'scan-bar__beam--animated': !reducedMotion }"
        ></span>
    </div>
</template>

<style scoped>
.scan-bar__beam--animated {
    animation: scan-bar-sweep 0.7s ease-in-out infinite;
}

.scan-bar__sweep {
    background: linear-gradient(to bottom, transparent 92%, color-mix(in srgb, var(--color-cyan-300) 18%, transparent));
    transform: translateY(100%);
}

.scan-bar__sweep--animated {
    animation: scan-bar-stage var(--dur-base) var(--ease-fly) 1 both;
}

@keyframes scan-bar-sweep {
    from {
        transform: translateX(-100%);
    }

    to {
        transform: translateX(300%);
    }
}

@keyframes scan-bar-stage {
    from {
        transform: translateY(-100%);
    }

    to {
        transform: translateY(100%);
    }
}
</style>
