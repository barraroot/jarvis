<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * Static placeholder of the JARVIS core (concentric rings + glowing center).
 * State-driven animations arrive with the core component in Phase 5.
 */
withDefaults(
    defineProps<{
        size?: number;
    }>(),
    { size: 220 },
);

const { t } = useI18n();
</script>

<template>
    <div
        class="relative mx-auto aspect-square max-w-full"
        :style="{ width: `${size}px` }"
        role="img"
        :aria-label="t('core.label')"
        data-testid="jarvis-core"
    >
        <svg viewBox="0 0 200 200" class="size-full" aria-hidden="true">
            <defs>
                <radialGradient id="jarvis-core-glow" cx="50%" cy="50%" r="50%">
                    <stop offset="0%" stop-color="#ffffff" />
                    <stop offset="25%" stop-color="#67e8f9" />
                    <stop offset="60%" stop-color="#22d3ee" stop-opacity="0.35" />
                    <stop offset="100%" stop-color="#22d3ee" stop-opacity="0" />
                </radialGradient>
            </defs>
            <circle cx="100" cy="100" r="94" fill="none" stroke="#164e63" stroke-width="1" />
            <circle cx="100" cy="100" r="86" fill="none" stroke="#22d3ee" stroke-width="3" stroke-dasharray="14 9" opacity="0.8" />
            <circle cx="100" cy="100" r="74" fill="none" stroke="#22d3ee" stroke-width="1" stroke-dasharray="2 6" opacity="0.6" />
            <path d="M 100 14 A 86 86 0 0 1 178 64" fill="none" stroke="#fbbf24" stroke-width="2" opacity="0.85" />
            <circle cx="100" cy="100" r="58" fill="none" stroke="#164e63" stroke-width="1" />
            <polygon points="100,58 136,79 136,121 100,142 64,121 64,79" fill="none" stroke="#67e8f9" stroke-width="1.5" opacity="0.7" />
            <circle cx="100" cy="100" r="42" fill="url(#jarvis-core-glow)" />
            <circle cx="100" cy="100" r="13" fill="#ffffff" opacity="0.95" />
        </svg>
    </div>
</template>
