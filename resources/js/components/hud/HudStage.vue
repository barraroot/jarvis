<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AgentsPanel from '@/components/hud/AgentsPanel.vue';
import JarvisCore from '@/components/hud/JarvisCore.vue';
import ScanBar from '@/components/hud/ScanBar.vue';
import VisualizationPanel from '@/components/hud/VisualizationPanel.vue';
import {
    HUD_STAGE_CORE_DELAY_CLOSE_MS,
    HUD_STAGE_CORE_DELAY_OPEN_MS,
    HUD_STAGE_EASE_X,
    HUD_STAGE_EASE_Y,
    HUD_STAGE_SCAN_MS,
    HUD_STAGE_TRANSITION_MS,
} from '@/components/hud/constants';
import { useLayoutMode } from '@/composables/useLayoutMode';
import { isEditableTarget } from '@/composables/usePushToTalk';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { useAssistantStore } from '@/stores/assistant';
import { useTaskTimelineStore } from '@/stores/taskTimeline';
import { useTasksStore } from '@/stores/tasks';
import { useVisualizationsStore } from '@/stores/visualizations';
import { useVoiceStore } from '@/stores/voice';
import type { TaskSummary } from '@/types/models';

/** `center` = focus mode, core alone (UI-05); `corner` = work mode with the panels (UI-06); `stacked` = tablet/mobile. */
type StageState = 'center' | 'corner' | 'stacked';

const props = withDefaults(
    defineProps<{
        /** AGENTES drawer opened from the header (`Alt+1`) while no visualization is open. */
        agentsDrawerOpen?: boolean;
    }>(),
    { agentsDrawerOpen: false },
);

const emit = defineEmits<{
    'update:agentsDrawerOpen': [open: boolean];
}>();

const { t } = useI18n();
const assistant = useAssistantStore();
const voice = useVoiceStore();
const visualizations = useVisualizationsStore();
const tasks = useTasksStore();
const timeline = useTaskTimelineStore();
const { mode } = useLayoutMode();
const reducedMotion = useReducedMotion();

const stageState = computed<StageState>(() => {
    if (mode.value !== 'desktop') {
        return 'stacked';
    }

    return visualizations.isOpen ? 'corner' : 'center';
});

const showPanels = computed(() => stageState.value !== 'center');
const showDrawer = computed(() => props.agentsDrawerOpen && stageState.value === 'center');

const containerClasses = computed(() => {
    switch (stageState.value) {
        case 'corner':
            return 'grid h-[calc(100dvh-13rem)] grid-cols-[18rem_minmax(0,1fr)] grid-rows-[auto_minmax(0,1fr)] gap-4';
        case 'stacked':
            return 'flex flex-col gap-4';
        default:
            return 'flex min-h-[calc(100dvh-13rem)] flex-col items-center justify-center gap-6';
    }
});

const coreClasses = computed(() => [
    stageState.value === 'corner' ? 'col-start-1 row-start-1 w-28 self-start justify-self-start' : '',
    stageState.value === 'stacked' ? 'w-28 self-center' : '',
    stageState.value === 'center' ? 'w-auto' : '',
    reducedMotion.value ? '' : 'will-change-transform',
]);

/** AGENTES grows below the core (3), after the scan and the core take off; leaves after VISUALIZAÇÃO on close. */
const agentsTransition = computed(() => ({
    css: !reducedMotion.value,
    enterActiveClass: 'origin-top transition-all duration-[700ms] delay-[350ms] ease-out',
    enterFromClass: 'opacity-0 scale-y-0',
    leaveActiveClass: 'origin-top transition-all duration-[350ms] delay-[150ms] ease-in',
    leaveToClass: 'opacity-0 scale-y-0',
}));

/** VISUALIZAÇÃO emerges on the right from below (4); it is the first to leave on close. */
const visualizationTransition = computed(() => ({
    css: !reducedMotion.value,
    enterActiveClass: 'transition-all duration-[700ms] delay-[500ms] ease-out',
    enterFromClass: 'opacity-0 translate-y-8',
    leaveActiveClass: 'transition-all duration-[350ms] ease-in',
    leaveToClass: 'opacity-0 translate-y-8',
}));

const drawerTransition = computed(() => ({
    css: !reducedMotion.value,
    enterActiveClass: 'transition-all duration-[350ms] ease-out',
    enterFromClass: 'opacity-0 translate-x-6',
    leaveActiveClass: 'transition-all duration-[350ms] ease-in',
    leaveToClass: 'opacity-0 translate-x-6',
}));

/** Outer box flies on the X axis, the inner one on Y with the scale, so the path curves (UI-06). */
const coreRef = ref<HTMLElement | null>(null);
const coreFlightRef = ref<HTMLElement | null>(null);
const scanning = ref(false);
/** Every flight replays the blurred halo trail. */
const haloKey = ref(0);
let scanTimer: ReturnType<typeof setTimeout> | null = null;

function startScan(): void {
    if (scanTimer !== null) {
        clearTimeout(scanTimer);
    }

    scanning.value = true;
    scanTimer = setTimeout(() => {
        scanning.value = false;
        scanTimer = null;
    }, HUD_STAGE_SCAN_MS);
}

/**
 * Signature transition (UI-06): the old box is measured before the DOM
 * update, then two KeyframeEffects bring the core from it to its new place —
 * X with `--ease-fly`, Y (plus the 112 px shrink) with `--ease-curve-y`, both
 * in `--dur-fly`. Opening sweeps the scan bar first; closing runs the reverse
 * order (panels leave, then the core flies back). Reduced motion applies the
 * final state at once (RNF-01).
 */
watch(
    stageState,
    async (next, previous) => {
        const outer = coreRef.value;
        const inner = coreFlightRef.value;

        if (outer === null || inner === null || reducedMotion.value || next === 'stacked' || previous === 'stacked') {
            return;
        }

        const opening = next === 'corner';

        if (opening) {
            startScan();
        }

        const first = outer.getBoundingClientRect();

        await nextTick();

        const last = outer.getBoundingClientRect();

        if (last.width === 0 || typeof outer.animate !== 'function' || typeof inner.animate !== 'function') {
            return;
        }

        const timing: KeyframeAnimationOptions = {
            duration: HUD_STAGE_TRANSITION_MS,
            delay: opening ? HUD_STAGE_CORE_DELAY_OPEN_MS : HUD_STAGE_CORE_DELAY_CLOSE_MS,
            fill: 'backwards',
        };

        outer.animate([{ transform: `translateX(${first.left - last.left}px)` }, { transform: 'translateX(0)' }], {
            ...timing,
            easing: HUD_STAGE_EASE_X,
        });
        inner.animate(
            [
                { transformOrigin: 'top left', transform: `translateY(${first.top - last.top}px) scale(${first.width / last.width})` },
                { transformOrigin: 'top left', transform: 'translateY(0) scale(1)' },
            ],
            { ...timing, easing: HUD_STAGE_EASE_Y },
        );
        haloKey.value += 1;
    },
    { flush: 'pre' },
);

/** Opening a visualization brings AGENTES into the stage, so the drawer closes. */
watch(
    () => visualizations.isOpen,
    (open) => {
        if (open && props.agentsDrawerOpen) {
            emit('update:agentsDrawerOpen', false);
        }
    },
);

/** Amber waiting ring while any task waits for a decision (UI-08). */
const waitingApproval = computed(() => tasks.tasks.some((task) => task.status === 'waiting_approval'));

/** Remaining share of the listening window, draining the LISTENING arc (UI-07). */
const countdownRatio = computed<number | null>(() => {
    const seconds = voice.countdownSeconds;
    const windowSeconds = voice.listeningWindowSeconds;

    if (seconds === null || !(windowSeconds > 0)) {
        return null;
    }

    return Math.min(1, Math.max(0, seconds / windowSeconds));
});

/**
 * Replays the core error flash once per task that reaches `failed` (UI-21).
 * Tasks already failed when the stage mounts never flash.
 */
const errorFlashKey = ref(0);
const isFailed = (task: TaskSummary): boolean => task.status === 'failed';
const flashedTaskIds = new Set(Object.values(timeline.snapshots).filter(isFailed).map((task) => String(task.id)));

watch(
    () => timeline.snapshots,
    (snapshots) => {
        const newlyFailed = Object.values(snapshots).filter((task) => isFailed(task) && !flashedTaskIds.has(String(task.id)));

        if (newlyFailed.length === 0) {
            return;
        }

        newlyFailed.forEach((task) => flashedTaskIds.add(String(task.id)));
        errorFlashKey.value += 1;
    },
);

/**
 * `Esc` closes the visualization like `×` (RF-11), once, unless the focus
 * is in an editable field or a modal/drawer (`aria-modal`) is open.
 */
function onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Escape' || event.defaultPrevented || !visualizations.isOpen) {
        return;
    }

    if (isEditableTarget(event.target) || document.querySelector('[aria-modal="true"]') !== null) {
        return;
    }

    void visualizations.close();
}

onMounted(() => {
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);

    if (scanTimer !== null) {
        clearTimeout(scanTimer);
    }
});
</script>

<template>
    <div
        class="relative w-full min-w-0"
        :class="containerClasses"
        :aria-label="t('stage.label')"
        :data-stage="stageState"
        :data-motion="reducedMotion ? 'reduced' : 'full'"
        data-testid="hud-stage"
    >
        <ScanBar v-if="scanning && !reducedMotion" variant="sweep" />

        <div ref="coreRef" class="relative shrink-0" :class="coreClasses" data-testid="hud-stage-core">
            <div ref="coreFlightRef" data-testid="stage-core-flight">
                <span
                    v-if="haloKey > 0 && !reducedMotion"
                    :key="haloKey"
                    class="stage__halo pointer-events-none absolute inset-0"
                    aria-hidden="true"
                    data-testid="stage-halo"
                ></span>
                <JarvisCore
                    :state="assistant.state"
                    :level="assistant.audioLevel"
                    :voice-phase="voice.available ? voice.phase : null"
                    :mic-level="voice.micLevel"
                    :output-level="voice.outputLevel"
                    :muted="voice.muted"
                    :size="stageState === 'center' ? 'full' : 'compact'"
                    :waiting-approval="waitingApproval"
                    :countdown-ratio="countdownRatio"
                    :countdown-seconds="voice.countdownSeconds"
                    :error-flash-key="errorFlashKey"
                />
            </div>
        </div>

        <Transition v-bind="agentsTransition">
            <div
                v-if="showPanels"
                class="min-w-0"
                :class="stageState === 'corner' ? 'col-start-1 row-start-2 min-h-0 overflow-y-auto' : 'w-full'"
                data-testid="hud-stage-agents"
            >
                <AgentsPanel />
            </div>
        </Transition>

        <Transition v-bind="visualizationTransition">
            <div
                v-if="visualizations.isOpen"
                class="min-w-0"
                :class="stageState === 'corner' ? 'col-start-2 row-span-2 row-start-1 min-h-0 overflow-y-auto' : 'w-full'"
                data-testid="hud-stage-visualization"
            >
                <VisualizationPanel />
            </div>
        </Transition>

        <div v-show="!visualizations.isOpen" class="w-full min-w-0" data-testid="hud-stage-content">
            <slot />
        </div>

        <Transition v-bind="drawerTransition">
            <aside
                v-if="showDrawer"
                class="glass fixed top-14 right-0 bottom-0 z-30 flex w-[min(22rem,90vw)] flex-col gap-3 overflow-y-auto border-l p-4"
                :aria-label="t('stage.agentsDrawer')"
                data-testid="hud-stage-agents-drawer"
                @keydown.esc="emit('update:agentsDrawerOpen', false)"
            >
                <button
                    type="button"
                    class="label-caps inline-flex min-h-11 min-w-11 items-center justify-center self-end px-2 text-label text-text-400 hover:text-cyan-300"
                    :aria-label="t('stage.closeAgents')"
                    data-testid="hud-stage-agents-drawer-close"
                    @click="emit('update:agentsDrawerOpen', false)"
                >
                    ×
                </button>
                <AgentsPanel />
            </aside>
        </Transition>
    </div>
</template>

<style scoped>
/* Blurred halo left behind by the flying core; fades once in --dur-fly (UI-06). */
.stage__halo {
    border-radius: 50%;
    background: radial-gradient(circle, color-mix(in srgb, var(--color-cyan-300) 35%, transparent), transparent 70%);
    filter: blur(18px);
    opacity: 0;
    animation: stage-halo-fade var(--dur-fly) var(--ease-fly) 1;
}

@keyframes stage-halo-fade {
    from {
        opacity: 0.8;
    }

    to {
        opacity: 0;
    }
}
</style>
