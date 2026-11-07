<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { AssistantState } from '@/types/models';
import type { VoicePhase } from '@/stores/voice';
import { resolveCoreFrame, type CoreFrame } from '@/lib/hud/coreFrame';
import { useReducedMotion } from '@/composables/useReducedMotion';
import {
    CORE_BAR_MAX_AMPLITUDE_COMPACT,
    CORE_BAR_MAX_AMPLITUDE_FULL,
    CORE_BAR_MIN_LENGTH,
    CORE_ERROR_FLASH_MS,
    CORE_ERROR_FLASH_REDUCED_MS,
    CORE_METER_SEGMENTS,
    CORE_RADIAL_BAR_COUNT,
    MAX_PARTICLES_COMPACT,
    MAX_PARTICLES_FULL,
} from '@/components/hud/constants';

const props = withDefaults(
    defineProps<{
        state: AssistantState;
        size?: 'full' | 'compact';
        /** Generic audio level 0–1; only speeds up the ambient particles. */
        level?: number;
        /** Realtime voice phase; null means voice is unavailable (UI-07). */
        voicePhase?: VoicePhase | null;
        /** Microphone level 0–1 for the `MIC` meter and the operator speech bars (UI-07, UI-09). */
        micLevel?: number;
        /** Output audio level 0–1 for the `VOZ` meter and the speaking bars (UI-07, UI-09). */
        outputLevel?: number;
        /** Muted microphone reads as 0 (RF-06). */
        muted?: boolean;
        /** At least one task in `waiting_approval`: amber dashed ring over the frame (UI-08). */
        waitingApproval?: boolean;
        /** Remaining share 0–1 of the listening window; drains the LISTENING arc (UI-07). */
        countdownRatio?: number | null;
        /** Seconds left in the listening window; the arc becomes this counter with reduced motion (UI-07). */
        countdownSeconds?: number | null;
        /** Every change replays the one-shot error flash (UI-07, UI-21). */
        errorFlashKey?: number;
    }>(),
    {
        size: 'full',
        level: 0,
        voicePhase: null,
        micLevel: 0,
        outputLevel: 0,
        muted: false,
        waitingApproval: false,
        countdownRatio: null,
        countdownSeconds: null,
        errorFlashKey: 0,
    },
);

const { t } = useI18n();
const blurId = `core-halo-blur-${useId()}`;
const reducedMotion = useReducedMotion();

const clamp = (value: number): number => (Number.isFinite(value) ? Math.min(1, Math.max(0, value)) : 0);

const resolved = computed(() => resolveCoreFrame({ assistantState: props.state, voicePhase: props.voicePhase }));
const frame = computed<CoreFrame>(() => resolved.value.frame);
const frameSlug = computed(() => frame.value.toLowerCase().replace('_', '-'));

const labelText = computed(() =>
    resolved.value.voiceUnavailable ? t('core.frames.voiceUnavailable') : t(`core.frames.${frame.value}`),
);

/** `MIC` and `VOZ` meters, 0–1; a muted microphone reads 0 (UI-09, RF-06). */
const micMeter = computed(() => (props.muted ? 0 : clamp(props.micLevel)));
const outputMeter = computed(() => clamp(props.outputLevel));

/** Meters exist only on the full core while voice is available (UI-09). */
const showMeters = computed(() => props.size === 'full' && props.voicePhase !== null);

const segmentIndexes = Array.from({ length: CORE_METER_SEGMENTS }, (_, index) => index);

function litSegments(level: number): number {
    return Math.round(level * CORE_METER_SEGMENTS);
}

/** Animation classes only exist when motion is allowed (RNF-01). */
function motion(...classes: string[]): string[] {
    return reducedMotion.value ? [] : classes;
}

const isSpeech = computed(() => frame.value === 'LISTENING_SPEECH' || frame.value === 'SPEAKING');

/** Outer dotted ring: 160 s asleep, 30 s listening, glitch when disconnected, still on error. */
const outerRingClasses = computed(() => {
    if (frame.value === 'ERROR') {
        return [];
    }

    return frame.value === 'DISCONNECTED' ? motion('core--animate-glitch') : motion('core--animate-spin');
});

const middleRingClasses = computed(() =>
    ['SLEEPING', 'ERROR', 'DISCONNECTED'].includes(frame.value) ? [] : motion('core--animate-spin-reverse'),
);

const nucleusClasses = computed(() => {
    if (frame.value === 'ERROR' || frame.value === 'DISCONNECTED') {
        return [];
    }

    return frame.value === 'THINKING' ? motion('core--animate-irregular') : motion('core--animate-breathe');
});

/** LISTENING arc empties with the listening window; reduced motion swaps it for a counter. */
const showArc = computed(() => frame.value === 'LISTENING' && props.countdownRatio !== null && !reducedMotion.value);
const arcCircumference = 1056;
const arcDash = computed(() => Math.round(clamp(props.countdownRatio ?? 0) * arcCircumference * 100) / 100);
const showCounter = computed(
    () => frame.value === 'LISTENING' && reducedMotion.value && props.countdownSeconds !== null,
);

/** Radial bars follow the microphone (operator speech) or the output audio (speaking). */
const barLevel = computed(() => (frame.value === 'LISTENING_SPEECH' ? micMeter.value : outputMeter.value));
const barAmplitude = computed(() =>
    props.size === 'compact' ? CORE_BAR_MAX_AMPLITUDE_COMPACT : CORE_BAR_MAX_AMPLITUDE_FULL,
);
/** Bar length in px: `3 + level × amplitude` (64 px full, 16 px compact). */
const barLength = computed(() => Math.round((CORE_BAR_MIN_LENGTH + barLevel.value * barAmplitude.value) * 100) / 100);
/** px → viewBox units: the 400-unit canvas is drawn at ~440 px (full) or 112 px (compact). */
const barUnits = computed(() => barLength.value * (props.size === 'compact' ? 400 / 112 : 400 / 440));
const barAngles = Array.from({ length: CORE_RADIAL_BAR_COUNT }, (_, index) => (index * 360) / CORE_RADIAL_BAR_COUNT);

/** With reduced motion the bars become a ring whose opacity follows the level. */
const showBars = computed(() => isSpeech.value && !reducedMotion.value);
const showLevelRing = computed(() => isSpeech.value && reducedMotion.value);
const levelRingOpacity = computed(() => Math.round((0.15 + barLevel.value * 0.85) * 100) / 100);

/** Halo: no glow while asleep; reduced-motion speech maps the output level to its opacity. */
const haloOpacity = computed(() =>
    frame.value === 'SPEAKING' && reducedMotion.value ? Math.round((0.12 + outputMeter.value * 0.4) * 100) / 100 : null,
);

const sonarDelays = [0, 550, 1100];
const orbitFast = [
    [200, 88, 4],
    [296, 140, 3],
    [290, 270, 3],
    [200, 318, 4],
    [110, 270, 3],
    [104, 130, 3],
] as const;
const orbitSlow = [
    [200, 120, 3],
    [268, 240, 3],
    [132, 240, 3],
] as const;
const filaments = [
    { d: 'M200 200 C 170 300, 120 330, 60 400', delay: 0 },
    { d: 'M200 200 C 190 300, 150 360, 110 420', delay: 300 },
    { d: 'M200 200 C 150 260, 90 300, 20 340', delay: 600 },
];
const segments = [
    'M200 60 A140 140 0 0 1 299 101',
    'M340 200 A140 140 0 0 1 299 299',
    'M200 340 A140 140 0 0 1 101 299',
    'M60 200 A140 140 0 0 1 101 101',
];

/**
 * One-shot error flash (UI-07): entering ERROR or a new `errorFlashKey`
 * flashes the nucleus and breaks the ring once; reduced motion shows a red
 * border for 1 s instead.
 */
const flashing = ref(false);
/** Re-keys the flashed elements so the one-shot animation restarts. */
const flashCount = ref(0);
let flashTimer: ReturnType<typeof setTimeout> | null = null;

function clearFlashTimer(): void {
    if (flashTimer !== null) {
        clearTimeout(flashTimer);
        flashTimer = null;
    }
}

function flash(): void {
    clearFlashTimer();
    flashCount.value += 1;
    flashing.value = true;
    flashTimer = setTimeout(
        () => {
            flashing.value = false;
            flashTimer = null;
        },
        reducedMotion.value ? CORE_ERROR_FLASH_REDUCED_MS : CORE_ERROR_FLASH_MS,
    );
}

watch(
    () => props.errorFlashKey,
    (current, previous) => {
        if (current !== previous) {
            flash();
        }
    },
);

watch(frame, (current, previous) => {
    if (current === 'ERROR' && previous !== 'ERROR') {
        flash();
    }
});

onMounted(() => {
    if (frame.value === 'ERROR') {
        flash();
    }
});

onBeforeUnmount(clearFlashTimer);

const rootClasses = computed(() => [
    'core',
    `core--frame-${frameSlug.value}`,
    `core--${props.size}`,
    reducedMotion.value ? 'core--reduced-motion' : '',
    props.waitingApproval ? 'core--waiting-approval' : '',
    flashing.value ? 'core--flash' : '',
]);

/** Devices with at most 4 cores halve the particle budget (RNF-07). */
const weakDevice = (typeof navigator !== 'undefined' ? navigator.hardwareConcurrency : undefined) ?? 0;

const particleCount = computed(() => {
    if (reducedMotion.value || frame.value === 'SLEEPING' || frame.value === 'DISCONNECTED' || frame.value === 'ERROR') {
        return 0;
    }

    const base = props.size === 'compact' ? 24 : 96;
    const cap = props.size === 'compact' ? MAX_PARTICLES_COMPACT : MAX_PARTICLES_FULL;

    return Math.min(cap, Math.round(weakDevice > 0 && weakDevice <= 4 ? base / 2 : base));
});

const canvasSize = computed(() => (props.size === 'compact' ? 112 : 280));

const FRAME_COLORS: Record<CoreFrame, string> = {
    SLEEPING: '#7FA3BF',
    LISTENING: '#1F8FD1',
    LISTENING_SPEECH: '#FFB547',
    SPEAKING: '#4FE3F0',
    THINKING: '#A6F7FF',
    EXECUTING: '#4FE3F0',
    ERROR: '#FF5A6E',
    DISCONNECTED: '#6B7F8F',
};

interface Particle {
    angle: number;
    radius: number;
    /** +1 / −1: the two orbits turn in opposite directions. */
    direction: number;
    /** Seconds per revolution (6 s and 9 s while THINKING). */
    period: number;
    size: number;
    alpha: number;
}

const canvasRef = ref<HTMLCanvasElement | null>(null);
let particles: Particle[] = [];
let animationFrame: number | null = null;

function spawnParticles(count: number, radius: number): Particle[] {
    return Array.from({ length: count }, (_, index) => {
        const inner = index % 2 === 0;

        return {
            angle: Math.random() * Math.PI * 2,
            radius: radius * (inner ? 0.5 + Math.random() * 0.12 : 0.72 + Math.random() * 0.12),
            direction: inner ? 1 : -1,
            period: inner ? 6 : 9,
            size: 0.8 + Math.random() * 1.4,
            alpha: 0.3 + Math.random() * 0.6,
        };
    });
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
    const pace = frame.value === 'THINKING' ? 1 : 0.25 + clamp(props.level) * 0.5;

    context.clearRect(0, 0, canvas.width, canvas.height);
    context.fillStyle = FRAME_COLORS[frame.value];

    for (const particle of particles) {
        particle.angle += ((Math.PI * 2) / (particle.period * 60)) * particle.direction * pace;

        context.globalAlpha = particle.alpha;
        context.beginPath();
        context.arc(
            center + Math.cos(particle.angle) * particle.radius,
            center + Math.sin(particle.angle) * particle.radius,
            particle.size,
            0,
            Math.PI * 2,
        );
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
</script>

<template>
    <div
        class="relative mx-auto flex max-w-full flex-col items-center"
        :class="rootClasses"
        role="img"
        :aria-label="t('core.label')"
        :data-frame="frame"
        data-testid="jarvis-core"
    >
        <div class="core__row flex max-w-full items-center">
            <div
                v-if="showMeters"
                class="core__meter core__meter--mic flex flex-col items-center gap-2"
                role="meter"
                :aria-label="t('core.micMeter')"
                aria-valuemin="0"
                aria-valuemax="1"
                :aria-valuenow="micMeter"
                data-testid="core-meter-mic"
            >
                <span class="flex flex-col-reverse gap-1" aria-hidden="true">
                    <span
                        v-for="index in segmentIndexes"
                        :key="index"
                        class="core__meter-segment"
                        :class="{ 'core__meter-segment--on': index < litSegments(micMeter) }"
                        data-testid="core-meter-mic-segment"
                    ></span>
                </span>
                <span class="label-caps text-[11px] text-text-400">{{ t('core.mic') }}</span>
            </div>

            <div class="core__body relative aspect-square">
                <canvas
                    ref="canvasRef"
                    class="absolute inset-0 size-full"
                    :width="canvasSize"
                    :height="canvasSize"
                    :data-particles="particleCount"
                    aria-hidden="true"
                ></canvas>

                <svg viewBox="0 0 400 400" class="core__svg absolute inset-0 size-full" aria-hidden="true">
                    <defs>
                        <filter :id="blurId" x="-50%" y="-50%" width="200%" height="200%">
                            <feGaussianBlur stdDeviation="22" />
                        </filter>
                    </defs>

                    <!-- UI-08: amber dashed "waiting approval" ring over the frame. -->
                    <circle
                        v-if="waitingApproval"
                        class="core__approval-ring"
                        :class="motion('core--animate-approval')"
                        cx="200"
                        cy="200"
                        r="196"
                        data-testid="core-approval-ring"
                    />

                    <circle
                        class="core__ring-outer"
                        :class="outerRingClasses"
                        cx="200"
                        cy="200"
                        r="186"
                        data-testid="core-ring-outer"
                    />
                    <g v-if="frame === 'DISCONNECTED'" data-testid="core-disconnect">
                        <circle class="core__ring-broken" :class="motion('core--animate-glitch')" cx="200" cy="200" r="176" />
                    </g>
                    <circle class="core__ring-middle" :class="middleRingClasses" cx="200" cy="200" r="150" />

                    <circle
                        v-if="showArc"
                        class="core__arc"
                        cx="200"
                        cy="200"
                        r="168"
                        :stroke-dasharray="`${arcDash} ${arcCircumference}`"
                        :data-ratio="countdownRatio"
                        data-testid="core-arc"
                    />

                    <g v-if="frame === 'SPEAKING' && !reducedMotion" data-testid="core-sonar">
                        <circle
                            v-for="delay in sonarDelays"
                            :key="delay"
                            class="core__sonar core--animate-sonar"
                            :style="{ animationDelay: `${delay}ms` }"
                            cx="200"
                            cy="200"
                            r="150"
                        />
                    </g>

                    <template v-if="frame === 'THINKING'">
                        <g class="core__orbit" :class="motion('core--animate-orbit')" data-testid="core-orbit">
                            <circle v-for="[cx, cy, r] in orbitFast" :key="`${cx}-${cy}`" :cx="cx" :cy="cy" :r="r" />
                        </g>
                        <g class="core__orbit core__orbit--slow" :class="motion('core--animate-orbit')" data-testid="core-orbit">
                            <circle v-for="[cx, cy, r] in orbitSlow" :key="`${cx}-${cy}`" :cx="cx" :cy="cy" :r="r" />
                        </g>
                    </template>

                    <g v-if="frame === 'EXECUTING'" data-testid="core-filaments">
                        <path
                            v-for="filament in filaments"
                            :key="filament.delay"
                            class="core__filament"
                            :class="reducedMotion ? 'core__filament--static' : 'core--animate-flow'"
                            :style="reducedMotion ? undefined : { animationDelay: `${filament.delay}ms` }"
                            :d="filament.d"
                            data-testid="core-filament"
                        />
                    </g>

                    <g v-if="showBars" data-testid="core-bars">
                        <line
                            v-for="angle in barAngles"
                            :key="angle"
                            class="core__bar"
                            x1="200"
                            y1="76"
                            x2="200"
                            :y2="76 - barUnits"
                            :transform="`rotate(${angle} 200 200)`"
                            :data-length="barLength"
                            data-testid="core-bar"
                        />
                    </g>
                    <circle
                        v-if="showLevelRing"
                        class="core__level-ring"
                        cx="200"
                        cy="200"
                        r="124"
                        :opacity="levelRingOpacity"
                        data-testid="core-level-ring"
                    />

                    <g :key="`segments-${flashCount}`" class="core__segments" data-testid="core-segments">
                        <path v-for="segment in segments" :key="segment" :d="segment" />
                    </g>
                    <circle class="core__error-border" cx="200" cy="200" r="140" />

                    <circle
                        v-if="frame !== 'SLEEPING'"
                        class="core__halo"
                        :class="nucleusClasses"
                        :style="haloOpacity === null ? undefined : { opacity: haloOpacity }"
                        :filter="`url(#${blurId})`"
                        cx="200"
                        cy="200"
                        r="90"
                        data-testid="core-halo"
                    />
                    <circle
                        :key="`nucleus-${flashCount}`"
                        class="core__nucleus"
                        :class="nucleusClasses" cx="200" cy="200" r="46" data-testid="core-nucleus" />
                    <circle class="core__nucleus-inner" cx="200" cy="200" r="18" />

                    <text
                        v-if="showCounter"
                        class="core__counter"
                        x="200"
                        y="214"
                        text-anchor="middle"
                        data-testid="core-countdown"
                    >
                        {{ countdownSeconds }}
                    </text>
                </svg>
            </div>

            <div
                v-if="showMeters"
                class="core__meter core__meter--output flex flex-col items-center gap-2"
                role="meter"
                :aria-label="t('core.outputMeter')"
                aria-valuemin="0"
                aria-valuemax="1"
                :aria-valuenow="outputMeter"
                data-testid="core-meter-output"
            >
                <span class="flex flex-col-reverse gap-1" aria-hidden="true">
                    <span
                        v-for="index in segmentIndexes"
                        :key="index"
                        class="core__meter-segment"
                        :class="{ 'core__meter-segment--on': index < litSegments(outputMeter) }"
                        data-testid="core-meter-output-segment"
                    ></span>
                </span>
                <span class="label-caps text-[11px] text-text-400">{{ t('core.output') }}</span>
            </div>
        </div>

        <span class="core__label label-caps mt-2" data-testid="core-label">{{ labelText }}</span>
    </div>
</template>

<style scoped>
/*
| Frame palette and motion (UI-07, `board-04-jarvis-core.html`). Continuous
| animations live only on `core--animate-*` classes, which the component
| skips with reduced motion, and animate only transform, opacity or
| stroke-dash* (RNF-07).
*/
.core {
    --core-c: var(--color-text-400);
    color: var(--core-c);
    transition:
        color 200ms ease-out,
        filter 200ms ease-out;
}

.core--frame-listening {
    --core-c: var(--color-ocean-400);
}

.core--frame-listening-speech {
    --core-c: var(--color-amber-400);
}

.core--frame-speaking,
.core--frame-executing {
    --core-c: var(--color-cyan-300);
}

.core--frame-thinking {
    --core-c: var(--color-biolum-200);
}

.core--frame-error {
    --core-c: var(--color-danger-400);
}

.core--frame-disconnected {
    --core-c: #6b7f8f;
    filter: saturate(0.2);
}

.core--full .core__body {
    width: clamp(15rem, 34vw, 27.5rem);
}

.core--full .core__row {
    gap: clamp(1rem, 4vw, 3rem);
}

.core--compact .core__body {
    width: 112px;
}

.core svg {
    overflow: visible;
}

.core__ring-outer,
.core__ring-middle,
.core__ring-broken,
.core__arc,
.core__sonar,
.core__orbit,
.core__segments,
.core__halo,
.core__nucleus,
.core__approval-ring,
.core__level-ring,
.core__error-border {
    transform-box: view-box;
    transform-origin: 200px 200px;
}

.core__ring-outer {
    fill: none;
    stroke: var(--core-c);
    stroke-width: 1.2;
    stroke-dasharray: 2 11;
    opacity: 0.55;
    transition: stroke 200ms ease-out;
}

.core--frame-sleeping .core__ring-outer {
    opacity: 0.3;
}

.core--frame-disconnected .core__ring-outer {
    opacity: 0.2;
}

.core__ring-broken {
    fill: none;
    stroke: var(--core-c);
    stroke-width: 1.4;
    stroke-dasharray: 14 10;
    opacity: 0.7;
}

.core__ring-middle {
    fill: none;
    stroke: var(--core-c);
    stroke-width: 1;
    stroke-dasharray: 1 7;
    opacity: 0.4;
}

.core__arc {
    fill: none;
    stroke: var(--core-c);
    stroke-width: 4;
    stroke-linecap: round;
    opacity: 0.9;
    transform: rotate(-90deg);
    transform-origin: 200px 200px;
    transition: stroke-dasharray 1s linear;
}

.core__sonar {
    fill: none;
    stroke: var(--core-c);
    stroke-width: 1.5;
    opacity: 0;
}

.core__orbit circle {
    fill: var(--core-c);
}

.core__filament {
    fill: none;
    stroke: var(--core-c);
    stroke-width: 1.2;
    stroke-dasharray: 6 14;
    opacity: 0.85;
}

.core__filament--static {
    stroke-dasharray: none;
    opacity: 0.4;
}

.core__bar {
    stroke: var(--core-c);
    stroke-width: 3;
    filter: drop-shadow(0 0 4px var(--core-c));
}

.core--compact .core__bar {
    stroke-width: 6;
}

.core__level-ring {
    fill: none;
    stroke: var(--core-c);
    stroke-width: 4;
    transition: opacity 90ms linear;
}

.core__segments {
    fill: none;
    stroke: var(--core-c);
    stroke-width: 3;
    opacity: 0;
}

.core__error-border {
    fill: none;
    stroke: var(--color-danger-400);
    stroke-width: 4;
    opacity: 0;
}

.core__halo {
    fill: var(--core-c);
    opacity: 0.22;
    transition: opacity 200ms ease-out;
}

.core__nucleus {
    fill: var(--core-c);
    transition:
        fill 200ms ease-out,
        opacity 200ms ease-out;
}

.core--frame-sleeping .core__nucleus {
    opacity: 0.35;
}

.core__nucleus-inner {
    fill: var(--color-text-100);
    opacity: 0.85;
}

.core--frame-sleeping .core__nucleus-inner {
    opacity: 0.3;
}

.core--reduced-motion.core--frame-thinking .core__nucleus {
    opacity: 0.75;
}

.core__approval-ring {
    fill: none;
    stroke: var(--color-amber-400);
    stroke-width: 2;
    stroke-dasharray: 10 8;
    opacity: 0.9;
}

.core__counter {
    fill: var(--color-text-100);
    font-family: var(--font-mono);
    font-size: 44px;
    font-variant-numeric: tabular-nums;
}

.core__label {
    font-size: var(--text-label);
    color: var(--core-c);
    transition: color 200ms ease-out;
}

.core--compact .core__label {
    font-size: 10px;
}

.core__meter--mic {
    --meter-c: var(--color-amber-400);
}

.core__meter--output {
    --meter-c: var(--color-cyan-300);
}

.core__meter-segment {
    display: block;
    width: 28px;
    height: 8px;
    background: color-mix(in srgb, var(--color-ocean-600) 35%, transparent);
    clip-path: polygon(3px 0, 100% 0, calc(100% - 3px) 100%, 0 100%);
    transition: background-color var(--dur-fast) linear;
}

.core__meter-segment--on {
    background: var(--meter-c);
}

/* Continuous motion (only with motion allowed). */
.core--animate-spin {
    animation: core-spin 60s linear infinite;
}

.core--frame-sleeping .core--animate-spin {
    animation-duration: 160s;
}

.core--frame-listening .core--animate-spin,
.core--frame-listening-speech .core--animate-spin,
.core--frame-listening .core--animate-spin-reverse {
    animation-duration: 30s;
}

.core--animate-spin-reverse {
    animation: core-spin 40s linear infinite reverse;
}

.core--animate-glitch {
    animation: core-glitch 3s steps(2) infinite;
}

.core--animate-breathe {
    animation: core-breathe var(--dur-breathe) ease-in-out infinite;
}

.core--frame-listening-speech .core--animate-breathe,
.core--frame-speaking .core--animate-breathe {
    animation-duration: 1.4s;
}

.core--animate-irregular {
    animation: core-irregular 1.1s ease-in-out infinite;
}

.core--animate-sonar {
    animation: core-sonar 1.6s var(--ease-sonar) infinite;
}

.core--animate-orbit {
    animation: core-spin 6s linear infinite;
}

.core__orbit--slow.core--animate-orbit {
    animation-duration: 9s;
    animation-direction: reverse;
}

.core--animate-flow {
    animation: core-flow 1.2s linear infinite;
}

.core--animate-approval {
    animation:
        core-spin 20s linear infinite,
        core-pulse-opacity 1.4s ease-in-out infinite;
}

/* One-shot error flash: nucleus 500 ms, ring breaks and recomposes in 900 ms. */
.core--flash:not(.core--reduced-motion) .core__nucleus {
    animation: core-flash 500ms ease-out 1;
}

.core--flash:not(.core--reduced-motion) .core__segments {
    animation: core-shatter 900ms var(--ease-fly) 1;
}

.core--flash.core--reduced-motion .core__error-border {
    opacity: 1;
}

@keyframes core-spin {
    to {
        transform: rotate(360deg);
    }
}

@keyframes core-glitch {
    0%,
    100% {
        transform: translate(0, 0);
    }
    50% {
        transform: translate(2px, -1px);
    }
}

@keyframes core-breathe {
    0%,
    100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.06);
    }
}

@keyframes core-irregular {
    0% {
        transform: scale(1);
    }
    30% {
        transform: scale(1.12);
    }
    45% {
        transform: scale(0.96);
    }
    70% {
        transform: scale(1.08);
    }
    100% {
        transform: scale(1);
    }
}

@keyframes core-sonar {
    0% {
        transform: scale(0.45);
        opacity: 0.9;
    }
    100% {
        transform: scale(1.35);
        opacity: 0;
    }
}

@keyframes core-flow {
    to {
        stroke-dashoffset: -40;
    }
}

@keyframes core-pulse-opacity {
    0%,
    100% {
        opacity: 0.9;
    }
    50% {
        opacity: 0.45;
    }
}

@keyframes core-flash {
    0% {
        fill: #ffffff;
    }
    100% {
        fill: var(--core-c);
    }
}

@keyframes core-shatter {
    0% {
        opacity: 1;
        transform: rotate(0deg) scale(1);
    }
    35% {
        opacity: 1;
        transform: rotate(6deg) scale(1.06);
    }
    70% {
        opacity: 1;
        transform: rotate(-6deg) scale(1.02);
    }
    100% {
        opacity: 0;
        transform: rotate(0deg) scale(1);
    }
}
</style>
