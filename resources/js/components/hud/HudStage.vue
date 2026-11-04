<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AgentsPanel from '@/components/hud/AgentsPanel.vue';
import JarvisCore from '@/components/hud/JarvisCore.vue';
import VisualizationPanel from '@/components/hud/VisualizationPanel.vue';
import { HUD_STAGE_TRANSITION_MS } from '@/components/hud/constants';
import { useLayoutMode } from '@/composables/useLayoutMode';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { useAssistantStore } from '@/stores/assistant';
import { useVisualizationsStore } from '@/stores/visualizations';
import { useVoiceStore } from '@/stores/voice';

/** `center` = big core alone, `corner` = core top-left with the panels (UI-09), `stacked` = tablet/mobile. */
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
    stageState.value === 'stacked' ? 'w-24 self-center' : '',
    stageState.value === 'center' ? 'w-60' : '',
    reducedMotion.value ? '' : 'will-change-transform',
]);

/** Panels fade/slide in with the core move; reduced motion drops every transition class (RNF-06). */
const panelTransition = computed(() => ({
    css: !reducedMotion.value,
    enterActiveClass: 'transition-all duration-[600ms] ease-out',
    enterFromClass: 'opacity-0 translate-x-6',
    leaveActiveClass: 'transition-all duration-[600ms] ease-in',
    leaveToClass: 'opacity-0 translate-x-6',
}));

const coreRef = ref<HTMLElement | null>(null);

/**
 * FLIP move of the core between the center and the corner (UI-09): the old
 * box is measured before the DOM update and the core animates from it to its
 * new place, in reverse when the visualization closes. Reduced motion skips
 * it and the final position applies at once (RNF-06).
 */
watch(
    stageState,
    async (next, previous) => {
        const element = coreRef.value;

        if (element === null || reducedMotion.value || next === 'stacked' || previous === 'stacked') {
            return;
        }

        const first = element.getBoundingClientRect();

        await nextTick();

        const last = element.getBoundingClientRect();

        if (last.width === 0 || typeof element.animate !== 'function') {
            return;
        }

        const translate = `translate(${first.left - last.left}px, ${first.top - last.top}px)`;

        element.animate(
            [
                { transformOrigin: 'top left', transform: `${translate} scale(${first.width / last.width})` },
                { transformOrigin: 'top left', transform: 'none' },
            ],
            { duration: HUD_STAGE_TRANSITION_MS, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' },
        );
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
        <div ref="coreRef" class="shrink-0" :class="coreClasses" data-testid="hud-stage-core">
            <JarvisCore
                :state="assistant.state"
                :level="assistant.audioLevel"
                :voice-phase="voice.available ? voice.phase : null"
                :mic-level="voice.micLevel"
                :output-level="voice.outputLevel"
                :muted="voice.muted"
                :size="stageState === 'center' ? 'full' : 'compact'"
            />
        </div>

        <Transition v-bind="panelTransition">
            <div
                v-if="showPanels"
                class="min-w-0"
                :class="stageState === 'corner' ? 'col-start-1 row-start-2 min-h-0 overflow-y-auto' : 'w-full'"
                data-testid="hud-stage-agents"
            >
                <AgentsPanel />
            </div>
        </Transition>

        <Transition v-bind="panelTransition">
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

        <Transition v-bind="panelTransition">
            <aside
                v-if="showDrawer"
                class="hud-glass fixed top-14 right-0 bottom-0 z-30 flex w-[min(22rem,90vw)] flex-col gap-3 overflow-y-auto border-l border-line p-4"
                :aria-label="t('stage.agentsDrawer')"
                data-testid="hud-stage-agents-drawer"
                @keydown.esc="emit('update:agentsDrawerOpen', false)"
            >
                <button
                    type="button"
                    class="hud-label inline-flex min-h-11 items-center justify-end self-end px-2 text-[0.6rem] text-muted hover:text-cyan"
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
