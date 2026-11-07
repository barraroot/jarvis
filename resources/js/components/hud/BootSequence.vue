<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { BOOT_ANIMATION, type BootAnimationStep, type BootItem } from '@/composables/useBootSequence';

const props = withDefaults(
    defineProps<{
        items: BootItem[];
        /** Reduced motion (RNF-01): the rings and the nucleus show their final state at once. */
        reducedMotion?: boolean;
    }>(),
    { reducedMotion: false },
);

const { t } = useI18n();

/** Outer to inner radius; the inner ring ignites first (`board-01-login-boot.html`). */
const RING_RADII = [110, 150, 186] as const;

function animationStyle(name: string, step: BootAnimationStep): Record<string, string> | undefined {
    if (props.reducedMotion) {
        return undefined;
    }

    return { animation: `${name} ${step.durationMs}ms var(--ease-fly) ${step.delayMs}ms both` };
}

const rings = computed(() =>
    RING_RADII.map((radius, index) => ({
        radius,
        style: animationStyle('boot-ignite', BOOT_ANIMATION.rings[index]!),
    })),
);

const coreStyle = computed(() => animationStyle('boot-wake', BOOT_ANIMATION.core));

const dotClasses: Record<BootItem['status'], string> = {
    pending: 'border-cyan-300 motion-safe:animate-hud-pulse',
    done: 'border-success-400 bg-success-400',
    error: 'border-danger-400 bg-danger-400',
};

const valueClasses: Record<BootItem['status'], string> = {
    pending: 'text-text-400',
    done: 'text-success-400',
    error: 'text-danger-400',
};
</script>

<template>
    <div class="relative flex w-full flex-col items-center" :data-motion="reducedMotion ? 'reduced' : 'full'" data-testid="boot-sequence">
        <svg
            viewBox="0 0 400 400"
            class="pointer-events-none absolute top-1/2 left-1/2 -z-10 size-[min(720px,140vw)] -translate-x-1/2 -translate-y-1/2 overflow-visible"
            aria-hidden="true"
            data-testid="boot-core"
        >
            <circle
                v-for="(ring, index) in rings"
                :key="ring.radius"
                class="boot-ring fill-none stroke-cyan-300 opacity-70"
                :class="{ 'boot-ring--animated': !reducedMotion }"
                cx="200"
                cy="200"
                :r="ring.radius"
                :stroke-width="index === 0 ? 2 : 1"
                :style="ring.style"
                data-testid="boot-ring"
            />
            <circle
                class="boot-nucleus origin-center fill-cyan-300 [transform-box:fill-box]"
                :class="{ 'boot-nucleus--animated': !reducedMotion }"
                cx="200"
                cy="200"
                r="44"
                :style="coreStyle"
                data-testid="boot-nucleus"
            />
        </svg>

        <section
            class="glass chamfer-md w-full max-w-[380px] px-7 py-6"
            role="status"
            aria-live="polite"
            :aria-label="t('boot.label')"
        >
            <h2 class="label-caps text-label text-cyan-300">{{ t('boot.title') }}</h2>
            <ul class="mt-3 flex flex-col">
                <li
                    v-for="item in props.items"
                    :key="item.key"
                    class="grid grid-cols-[20px_1fr_auto] items-center gap-2.5 border-t border-ocean-600/30 py-2.5 text-[13.5px]"
                    :data-status="item.status"
                    :data-testid="`boot-item-${item.key}`"
                >
                    <span class="size-2.5 rounded-full border-[1.5px]" :class="dotClasses[item.status]" aria-hidden="true"></span>
                    <span class="text-text-100">{{ t(`boot.${item.key}`) }}</span>
                    <span class="font-mono text-xs" :class="valueClasses[item.status]" data-testid="boot-item-value">
                        {{ item.status === 'error' ? t('boot.failed') : (item.value ?? '') }}
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>

