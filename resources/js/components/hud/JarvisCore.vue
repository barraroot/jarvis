<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { AssistantState } from '@/types/models';
import type { VoicePhase } from '@/stores/voice';
import {
    CORE_BAR_MAX_LENGTH,
    CORE_BAR_MIN_LENGTH,
    CORE_RADIAL_BAR_COUNT,
    MAX_PARTICLES_COMPACT,
    MAX_PARTICLES_FULL,
} from '@/components/hud/constants';

const props = withDefaults(
    defineProps<{
        state: AssistantState;
        size?: 'full' | 'compact';
        /** Audio level between 0 and 1 (microphone RMS / speech synthesis). */
        level?: number;
        /** Realtime voice phase (UI-05); null keeps the assistant-state visuals. */
        voicePhase?: VoicePhase | null;
        /** Microphone level 0–1 for the `MIC` meter and the user-speaking bars (UI-06). */
        micLevel?: number;
        /** Output audio level 0–1 for the `VOZ` meter and the speaking bars (UI-06). */
        outputLevel?: number;
        /** Muted microphone reads as 0 (RF-06). */
        muted?: boolean;
    }>(),
    { size: 'full', level: 0, voicePhase: null, micLevel: 0, outputLevel: 0, muted: false },
);

const { t } = useI18n();
const gradientId = `core-nucleus-gradient-${useId()}`;

const clamp = (value: number): number => (Number.isFinite(value) ? Math.min(1, Math.max(0, value)) : 0);

const clampedLevel = computed(() => clamp(props.level));

/** Faults keep their own visuals; otherwise the voice phase drives the core (UI-05). */
const activeVoicePhase = computed<VoicePhase | null>(() =>
    props.voicePhase !== null && props.state !== 'ERROR' && props.state !== 'DISCONNECTED' ? props.voicePhase : null,
);

/** `MIC` and `VOZ` meters, 0–1; a muted microphone reads 0 (UI-06, RF-06). */
const micMeter = computed(() => (props.muted ? 0 : clamp(props.micLevel)));
const outputMeter = computed(() => clamp(props.outputLevel));

/** Continuous rotation, pulsing and particles are skipped for reduced motion. */
const reducedMotion = ref(
    typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches,
);
let mediaQuery: MediaQueryList | null = null;

function syncReducedMotion(): void {
    reducedMotion.value = mediaQuery?.matches ?? false;
}

onMounted(() => {
    mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    syncReducedMotion();
    mediaQuery?.addEventListener('change', syncReducedMotion);
});

onBeforeUnmount(() => {
    mediaQuery?.removeEventListener('change', syncReducedMotion);
});

/** Devices with at most 4 cores halve the particle budget. */
const weakDevice = (typeof navigator !== 'undefined' ? navigator.hardwareConcurrency : undefined) ?? 0;

const particleCount = computed(() => {
    if (reducedMotion.value || props.state === 'SLEEPING' || props.state === 'DISCONNECTED' || props.state === 'ERROR') {
        return 0;
    }

    const base = props.size === 'compact' ? 24 : 96;
    const cap = props.size === 'compact' ? MAX_PARTICLES_COMPACT : MAX_PARTICLES_FULL;

    return Math.min(cap, Math.round(weakDevice > 0 && weakDevice <= 4 ? base / 2 : base));
});

const canvasSize = computed(() => (props.size === 'compact' ? 112 : 240));

/**
 * Rotation is continuous and per state; SLEEPING/DISCONNECTED are static.
 */
const spinClasses = computed(() => {
    if (reducedMotion.value || props.state === 'SLEEPING' || props.state === 'DISCONNECTED' || props.state === 'ERROR') {
        return [];
    }

    return props.state === 'THINKING' ? ['core--animate-spin-fast'] : ['core--animate-spin'];
});

/** Nucleus halo pulses; THINKING/LISTENING pulse faster. */
const nucleusClasses = computed(() => {
    if (reducedMotion.value) {
        return [];
    }

    if (props.state === 'LISTENING' || props.state === 'THINKING' || props.state === 'SPEAKING') {
        return ['core--animate-pulse-fast'];
    }

    return props.state === 'IDLE' ? ['core--animate-pulse'] : [];
});

/** THINKING alternates ring segments in waves. */
const segmentsClass = computed(() =>
    !reducedMotion.value && props.state === 'THINKING' ? 'core--animate-segments' : '',
);

const sweepClass = computed(() => (!reducedMotion.value && props.state === 'EXECUTING' ? 'core--animate-sweep' : ''));

const shakeClass = computed(() => (!reducedMotion.value && props.state === 'ERROR' ? 'core--animate-shake' : ''));

/**
 * LISTENING contracts the rings as the level rises; SPEAKING expands them.
 */
const ringScale = computed(() => {
    let scale = 1;

    if (props.state === 'LISTENING') {
        scale = 1 - clampedLevel.value * 0.06;
    } else if (props.state === 'SPEAKING') {
        scale = 1 + clampedLevel.value * 0.05;
    }

    return Math.round(scale * 1000) / 1000;
});

const rootClasses = computed(() => [
    'core',
    `core--${props.state.toLowerCase()}`,
    `core--${props.size}`,
    activeVoicePhase.value === null ? '' : `core--voice-${activeVoicePhase.value.replace('_', '-')}`,
    reducedMotion.value ? 'core--reduced-motion' : '',
]);

const labelClasses = computed(() => ['hud-label', `core__label core__label--${props.state.toLowerCase()}`]);

/** `OUVINDO`, `OUVINDO VOCÊ`, `FALANDO`, `Aguardando "Jarvis"`; other states keep `status.*` (UI-05). */
const labelText = computed(() =>
    activeVoicePhase.value === null ? t(`status.${props.state}`) : t(`core.voice.${activeVoicePhase.value}`),
);

/** Dotted rings rotate slowly while the window is open without speech. */
const dotsClasses = computed(() => (reducedMotion.value ? [] : ['core--animate-dots']));

/** Radial bars follow the microphone (user speaking) or the output audio (speaking). */
const barLevel = computed(() => {
    if (activeVoicePhase.value === 'user_speaking') {
        return micMeter.value;
    }

    return activeVoicePhase.value === 'speaking' ? outputMeter.value : 0;
});

const showBars = computed(() => activeVoicePhase.value === 'user_speaking' || activeVoicePhase.value === 'speaking');

const barLength = computed(
    () => Math.round((CORE_BAR_MIN_LENGTH + barLevel.value * (CORE_BAR_MAX_LENGTH - CORE_BAR_MIN_LENGTH)) * 100) / 100,
);

const barAngles = Array.from({ length: CORE_RADIAL_BAR_COUNT }, (_, index) => (index * 360) / CORE_RADIAL_BAR_COUNT);

/** Bars only ease between levels when motion is allowed (RNF-06). */
const barsClasses = computed(() => (reducedMotion.value ? [] : ['core__bars--animated']));

const particleColor = computed(() => {
    switch (props.state) {
        case 'LISTENING':
        case 'SPEAKING':
            return '#67e8f9';
        case 'THINKING':
            return '#a78bfa';
        case 'EXECUTING':
            return '#fbbf24';
        default:
            return '#22d3ee';
    }
});

interface Particle {
    angle: number;
    radius: number;
    speed: number;
    size: number;
    alpha: number;
}

const canvasRef = ref<HTMLCanvasElement | null>(null);
let particles: Particle[] = [];
let animationFrame: number | null = null;

function spawnParticles(count: number, radius: number): Particle[] {
    return Array.from({ length: count }, () => ({
        angle: Math.random() * Math.PI * 2,
        radius: radius * (0.45 + Math.random() * 0.45),
        speed: 0.002 + Math.random() * 0.006,
        size: 0.8 + Math.random() * 1.6,
        alpha: 0.35 + Math.random() * 0.5,
    }));
}

function drawFrame(): void {
    const canvas = canvasRef.value;

    if (canvas === null) {
        return;
    }

    const context = canvas.getContext('2d');

    // Headless test environments do not implement a 2D context: stay silent.
    if (context === null) {
        animationFrame = null;

        return;
    }

    const center = canvas.width / 2;

    context.clearRect(0, 0, canvas.width, canvas.height);
    context.fillStyle = particleColor.value;

    for (const particle of particles) {
        particle.angle += particle.speed * (0.4 + clampedLevel.value * 1.8);

        const x = center + Math.cos(particle.angle) * particle.radius;
        const y = center + Math.sin(particle.angle) * particle.radius * 0.82;

        context.globalAlpha = particle.alpha;
        context.beginPath();
        context.arc(x, y, particle.size, 0, Math.PI * 2);
        context.fill();
    }

    context.globalAlpha = 1;
    animationFrame = requestAnimationFrame(drawFrame);
}

function stopParticles(): void {
    if (animationFrame !== null) {
        cancelAnimationFrame(animationFrame);
        animationFrame = null;
    }
}

function restartParticles(): void {
    stopParticles();
    particles = spawnParticles(particleCount.value, canvasSize.value / 2);

    if (particleCount.value > 0) {
        animationFrame = requestAnimationFrame(drawFrame);
    }
}

watch([particleCount, canvasSize], restartParticles);

onMounted(restartParticles);
onBeforeUnmount(stopParticles);

const ticks = Array.from({ length: 60 }, (_, index) => index * 6);
</script>

<template>
    <div
        class="core relative mx-auto flex max-w-full flex-col items-center"
        :class="rootClasses"
        role="img"
        :aria-label="t('core.label')"
        data-testid="jarvis-core"
    >
        <div class="relative aspect-square w-full">
            <canvas
                ref="canvasRef"
                class="absolute inset-0 size-full"
                :width="canvasSize"
                :height="canvasSize"
                :data-particles="particleCount"
                aria-hidden="true"
            ></canvas>

            <svg viewBox="0 0 200 200" class="core__stage absolute inset-0 size-full" aria-hidden="true" :class="shakeClass">
                <defs>
                    <radialGradient :id="gradientId" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="#ffffff" />
                        <stop offset="30%" class="core__nucleus-stop" />
                        <stop offset="70%" stop-color="rgba(34, 211, 238, 0.35)" />
                        <stop offset="100%" stop-color="rgba(34, 211, 238, 0)" />
                    </radialGradient>
                </defs>

                <!-- Outer tick markers around the frame. -->
                <g class="core__ticks">
                    <line
                        v-for="tick in ticks"
                        :key="tick"
                        x1="100"
                        y1="4"
                        x2="100"
                        y2="9"
                        stroke-width="1"
                        :transform="`rotate(${tick} 100 100)`"
                        class="core__ring-dim"
                    />
                </g>

                <!-- Concentric segmented rings; the group rotates per state. -->
                <g class="core__rotor" :class="spinClasses">
                    <g class="core__scale-target" :style="{ transform: `scale(${ringScale})` }" data-testid="core-scale">
                        <!-- Outer ring: segments like painel1. -->
                        <circle
                            cx="100"
                            cy="100"
                            r="92"
                            fill="none"
                            stroke-width="2.5"
                            stroke-dasharray="26 11"
                            class="core__ring core__segment"
                        />
                        <circle
                            cx="100"
                            cy="100"
                            r="80"
                            fill="none"
                            stroke-width="1.5"
                            stroke-dasharray="7 5"
                            class="core__ring core__segment"
                        />
                        <circle
                            cx="100"
                            cy="100"
                            r="68"
                            fill="none"
                            stroke-width="1"
                            stroke-dasharray="2 5"
                            opacity="0.7"
                            class="core__ring core__segment"
                        />
                        <circle cx="100" cy="100" r="58" fill="none" stroke-width="1" class="core__ring-dim" />
                        <polygon
                            points="100,58 136,79 136,121 100,142 64,121 64,79"
                            fill="none"
                            stroke-width="1.5"
                            opacity="0.7"
                            class="core__ring"
                        />
                    </g>
                </g>

                <!-- OUVINDO: dotted rings around the nucleus (UI-05). -->
                <g
                    v-if="activeVoicePhase === 'listening'"
                    class="core__dots"
                    :class="dotsClasses"
                    data-testid="core-dots"
                >
                    <circle cx="100" cy="100" r="52" fill="none" stroke-width="1.5" stroke-dasharray="0.5 4" stroke-linecap="round" class="core__ring" />
                    <circle cx="100" cy="100" r="62" fill="none" stroke-width="1" stroke-dasharray="0.5 6" stroke-linecap="round" class="core__ring" />
                </g>

                <!-- OUVINDO VOCÊ / FALANDO: radial bars proportional to the level (UI-05). -->
                <g v-if="showBars" class="core__bars" :class="barsClasses" data-testid="core-bars">
                    <line
                        v-for="angle in barAngles"
                        :key="angle"
                        x1="100"
                        :y1="50"
                        x2="100"
                        :y2="50 - barLength"
                        stroke-width="2"
                        stroke-linecap="round"
                        :transform="`rotate(${angle} 100 100)`"
                        :data-length="barLength"
                        class="core__ring core__bar"
                        data-testid="core-bar"
                    />
                </g>

                <!-- EXECUTING: amber sweep arc on the outer ring (painel1). -->
                <circle
                    v-if="state === 'EXECUTING'"
                    cx="100"
                    cy="100"
                    r="97"
                    fill="none"
                    stroke="#fbbf24"
                    stroke-width="3"
                    stroke-linecap="round"
                    pathLength="100"
                    stroke-dasharray="26 74"
                    :class="sweepClass"
                    data-testid="core-sweep"
                />

                <!-- DISCONNECTED: broken-link glyph instead of activity. -->
                <g
                    v-if="state === 'DISCONNECTED'"
                    stroke="#94a3b8"
                    stroke-width="4"
                    stroke-linecap="round"
                    data-testid="core-disconnect"
                >
                    <path d="M 82 94 L 100 108" />
                    <path d="M 118 92 L 100 108" />
                </g>

                <!-- Luminous nucleus with halo (painel3). -->
                <g :class="nucleusClasses">
                    <circle cx="100" cy="100" r="46" fill="none" stroke-width="1" class="core__ring-dim" opacity="0.6" />
                    <circle cx="100" cy="100" r="40" fill="none" stroke-width="1" class="core__ring" opacity="0.8" />
                    <circle cx="100" cy="100" r="42" :fill="`url(#${gradientId})`" />
                    <circle cx="100" cy="100" r="14" class="core__nucleus-dot" opacity="0.95" />
                </g>
            </svg>
        </div>

        <!-- Side meters MIC (left) and VOZ (right), 0–1 (UI-06). -->
        <template v-if="voicePhase !== null">
            <div
                class="core__meter absolute left-0 top-1/2 flex -translate-y-1/2 flex-col items-center gap-1"
                role="meter"
                :aria-label="t('core.micMeter')"
                aria-valuemin="0"
                aria-valuemax="1"
                :aria-valuenow="micMeter"
                data-testid="core-meter-mic"
            >
                <span class="core__meter-track relative h-16 w-1.5 overflow-hidden rounded-full bg-line/60">
                    <span
                        class="core__meter-fill core__meter-fill--mic absolute inset-x-0 bottom-0 rounded-full"
                        :style="{ height: `${micMeter * 100}%` }"
                        data-testid="core-meter-mic-fill"
                    ></span>
                </span>
                <span class="hud-label font-mono text-[0.5rem] text-muted">{{ t('core.mic') }}</span>
            </div>
            <div
                class="core__meter absolute right-0 top-1/2 flex -translate-y-1/2 flex-col items-center gap-1"
                role="meter"
                :aria-label="t('core.outputMeter')"
                aria-valuemin="0"
                aria-valuemax="1"
                :aria-valuenow="outputMeter"
                data-testid="core-meter-output"
            >
                <span class="core__meter-track relative h-16 w-1.5 overflow-hidden rounded-full bg-line/60">
                    <span
                        class="core__meter-fill core__meter-fill--output absolute inset-x-0 bottom-0 rounded-full"
                        :style="{ height: `${outputMeter * 100}%` }"
                        data-testid="core-meter-output-fill"
                    ></span>
                </span>
                <span class="hud-label font-mono text-[0.5rem] text-muted">{{ t('core.output') }}</span>
            </div>
        </template>

        <div class="mt-2 flex items-center justify-center gap-1.5">
            <span class="core__label-dot size-1.5 shrink-0 rounded-full" aria-hidden="true"></span>
            <span data-testid="core-label" :class="labelClasses">{{ labelText }}</span>
        </div>
    </div>
</template>
