<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import AppHeader from '@/components/hud/AppHeader.vue';
import BottomNav from '@/components/hud/BottomNav.vue';
import CaptionsBar from '@/components/hud/CaptionsBar.vue';
import ConversationDrawer from '@/components/hud/ConversationDrawer.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import HudStage from '@/components/hud/HudStage.vue';
import InteractionFooter from '@/components/hud/InteractionFooter.vue';
import JarvisCore from '@/components/hud/JarvisCore.vue';
import SidePanel from '@/components/hud/SidePanel.vue';
import { useAssistantStore } from '@/stores/assistant';
import { useConnectionStore } from '@/stores/connection';
import { useVoiceStore } from '@/stores/voice';
import { useLayoutMode } from '@/composables/useLayoutMode';
import { usePersistentFlag } from '@/composables/usePersistentFlag';
import { SIDEBAR_COLLAPSED_STORAGE_KEY } from '@/layouts/constants';

const props = withDefaults(
    defineProps<{
        /** Voice runs text-only (RF-08a); the microphone controls are disabled. */
        voiceUnavailable?: boolean;
        /** The microphone is muted (RF-06). */
        muted?: boolean;
    }>(),
    { voiceUnavailable: false, muted: false },
);

/** `microphone` toggles the mute (RF-06); `sleep`/`wake` drive the voice window (RF-05). */
const emit = defineEmits<{
    sleep: [];
    wake: [];
    microphone: [];
    send: [payload: { content: string; source: 'text' | 'voice' }];
}>();

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const connection = useConnectionStore();
const assistant = useAssistantStore();
const voice = useVoiceStore();
const { mode } = useLayoutMode();

/** Sending (text, voice) is blocked while the realtime link is down. */
const sendingDisabled = computed(() => connection.isDisconnected);

/**
 * Tablet (768–1279px) collapse state, persisted between visits. Tablets start
 * collapsed so the conversation keeps the width; the toggle reopens the panel.
 */
const sidebarCollapsed = usePersistentFlag(SIDEBAR_COLLAPSED_STORAGE_KEY, mode.value === 'tablet');
/** Mobile (<768px) drawer, transient; agents/tasks open it as a bottom sheet. */
const drawerOpen = ref(false);
const drawerSection = ref<'agents' | 'tasks' | null>(null);
/** Bottom sheet height: half screen by default, expandable to full height. */
const sheetExpanded = ref(false);
const conversationsOpen = ref(false);

const isMobile = computed(() => mode.value === 'mobile');
/**
 * The `hud` route renders the stage (UI-09): no side panel on tablet/desktop,
 * the core and the AGENTES/VISUALIZAÇÃO panels live in the main area. Every
 * other route keeps the side panel layout.
 */
const isHudRoute = computed(() => route.name === 'hud');
/** AGENTES drawer of the stage (`Alt+1`) while the core is centered. */
const stageAgentsOpen = ref(false);
const isSheet = computed(() => isMobile.value && drawerOpen.value && drawerSection.value !== null);

watch(
    () => route.fullPath,
    () => {
        drawerOpen.value = false;
        stageAgentsOpen.value = false;
    },
);

/** Leaving mobile closes the transient drawer/sheet. */
watch(isMobile, (mobile) => {
    if (!mobile) {
        drawerOpen.value = false;
    }
});

const sidebarClasses = computed(() => [
    drawerOpen.value ? 'flex' : 'hidden',
    sidebarCollapsed.value ? 'md:hidden xl:flex' : 'md:flex',
    isSheet.value
        ? [
              'z-40 inset-x-0 left-0 w-full rounded-t-md border-t',
              sheetExpanded.value ? 'top-14 bottom-0' : 'top-auto bottom-0 max-h-[60dvh]',
          ]
        : 'z-30 bottom-0 left-0 top-14 w-[min(20rem,85vw)] border-r md:w-80',
]);

const offsetClasses = computed(() => {
    if (isHudRoute.value) {
        return '';
    }

    return sidebarCollapsed.value ? 'xl:pl-80' : 'md:pl-80';
});

function toggleSidebar(): void {
    sidebarCollapsed.value = !sidebarCollapsed.value;
}

function toggleDrawer(): void {
    drawerOpen.value = !drawerOpen.value;
    drawerSection.value = null;
}

/** Header agents (`Alt+1`): the stage drawer on the HUD (tablet/desktop), the side panel elsewhere. */
function onAgents(): void {
    if (isHudRoute.value && !isMobile.value) {
        stageAgentsOpen.value = !stageAgentsOpen.value;

        return;
    }

    openSection('agents');
}

function openSection(section: 'agents' | 'tasks'): void {
    if (drawerOpen.value && drawerSection.value === section) {
        drawerOpen.value = false;

        return;
    }

    drawerSection.value = section;
    sheetExpanded.value = false;
    drawerOpen.value = true;
}

/** `/nova` navigates to a fresh conversation view (Phase 8 wires the real creation). */
function onCommand(payload: { type: 'new' | 'remember' | 'tasks' | 'memories' }): void {
    if (payload.type === 'new') {
        void router.push({ name: 'hud' });
    }
}
</script>

<template>
    <div
        class="hud-grid relative min-h-dvh w-full overflow-x-hidden bg-bg text-text"
        :data-layout="mode"
        data-testid="hud-layout"
    >
        <header
            class="hud-glass fixed inset-x-0 top-0 z-40 flex h-14 items-center gap-3 border-b border-line px-3 sm:px-5"
            data-testid="layout-header"
        >
            <button
                type="button"
                class="inline-flex size-11 shrink-0 items-center justify-center rounded-sm border border-line text-cyan md:hidden"
                :aria-label="drawerOpen ? t('nav.closeMenu') : t('nav.openMenu')"
                :aria-expanded="drawerOpen"
                data-testid="drawer-toggle"
                @click="toggleDrawer"
            >
                <span class="flex w-4 flex-col gap-1">
                    <span class="h-px w-full bg-current"></span>
                    <span class="h-px w-full bg-current"></span>
                    <span class="h-px w-full bg-current"></span>
                </span>
            </button>
            <button
                v-if="!isHudRoute"
                type="button"
                class="hidden size-11 shrink-0 items-center justify-center rounded-sm border border-line text-cyan md:inline-flex xl:hidden"
                :aria-label="t('nav.toggleSidebar')"
                :aria-expanded="!sidebarCollapsed"
                data-testid="sidebar-toggle"
                @click="toggleSidebar"
            >
                <span class="block h-4 w-3 border-l-2 border-current"></span>
            </button>
            <slot name="header">
                <AppHeader
                    @sleep="emit('sleep')"
                    @wake="emit('wake')"
                    @microphone="emit('microphone')"
                    @agents="onAgents"
                    @conversations="conversationsOpen = true"
                />
            </slot>
        </header>

        <div
            v-if="drawerOpen"
            class="fixed inset-0 top-14 z-20 bg-bg/70 md:hidden"
            data-testid="drawer-backdrop"
            @click="drawerOpen = false"
        ></div>

        <aside
            v-if="!isHudRoute || isMobile"
            class="hud-glass fixed flex-col gap-4 overflow-y-auto border-line p-4"
            :class="sidebarClasses"
            :aria-label="t('layout.sidebar')"
            :data-sheet="isSheet ? (sheetExpanded ? 'expanded' : 'half') : undefined"
            data-testid="layout-sidebar"
        >
            <button
                v-if="isSheet"
                type="button"
                class="hud-label -mt-2 flex min-h-11 w-full shrink-0 flex-col items-center justify-center gap-1 text-[0.6rem] text-muted"
                :aria-expanded="sheetExpanded"
                :aria-label="sheetExpanded ? t('layout.sheetCollapse') : t('layout.sheetExpand')"
                data-testid="sheet-handle"
                @click="sheetExpanded = !sheetExpanded"
            >
                <span class="h-1 w-10 rounded-full bg-line"></span>
            </button>
            <slot name="sidebar">
                <SidePanel :expanded-section="drawerSection" />
            </slot>
        </aside>

        <ConversationDrawer v-model:open="conversationsOpen" />

        <div class="flex min-h-dvh min-w-0 flex-col pt-14" :class="offsetClasses">
            <div
                v-if="sendingDisabled"
                class="sticky top-14 z-20 border-b border-danger/60 bg-danger/15 px-4 py-2 text-center text-xs text-danger"
                role="alert"
                data-testid="disconnected-banner"
            >
                {{ t('layout.disconnectedBanner') }}
                <span v-if="connection.nextRetryDelayMs !== null" class="ml-2 text-muted">
                    {{ t('layout.reconnecting', { seconds: Math.ceil(connection.nextRetryDelayMs / 1000) }) }}
                </span>
            </div>
            <slot name="banner" />

            <!-- Mobile: the core moves out of the hidden side panel into a compact strip on top. -->
            <div
                v-if="isMobile && !isHudRoute"
                class="flex items-center justify-center border-b border-line/60 py-1"
                data-testid="mobile-core"
            >
                <div class="w-24">
                    <JarvisCore
                        :state="assistant.state"
                        :level="assistant.audioLevel"
                        :voice-phase="voice.available ? voice.phase : null"
                        :mic-level="voice.micLevel"
                        :output-level="voice.outputLevel"
                        :muted="voice.muted"
                        size="compact"
                    />
                </div>
            </div>

            <main
                class="min-w-0 flex-1 px-3 pt-4 pb-40 sm:px-5 md:pb-28"
                :aria-label="t('layout.main')"
                data-testid="layout-main"
            >
                <HudStage v-if="isHudRoute" v-model:agents-drawer-open="stageAgentsOpen">
                    <slot name="main">
                        <EmptyState />
                    </slot>
                </HudStage>
                <slot v-else name="main">
                    <EmptyState />
                </slot>
            </main>
        </div>

        <footer
            class="fixed inset-x-0 bottom-0 z-30 flex flex-col"
            :class="offsetClasses"
            :aria-label="t('layout.footer')"
            data-testid="layout-footer"
        >
            <!-- Live captions sit right above the text input (UI-07). -->
            <CaptionsBar class="hud-glass border-t border-line" />

            <fieldset
                class="hud-glass min-w-0 border-t border-line px-3 py-3 disabled:opacity-60 sm:px-5"
                :disabled="sendingDisabled"
                data-testid="layout-footer-controls"
            >
                <slot name="footer" :sending-disabled="sendingDisabled">
                    <InteractionFooter
                        :disabled="sendingDisabled"
                        :muted="props.muted"
                        :voice-unavailable="props.voiceUnavailable"
                        @send="emit('send', $event)"
                        @mute="emit('microphone')"
                        @command="onCommand"
                    />
                </slot>
            </fieldset>

            <BottomNav :disabled="sendingDisabled" @select="openSection" @microphone="emit('microphone')" />
        </footer>
    </div>
</template>
