<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import AppHeader from '@/components/hud/AppHeader.vue';
import BottomNav from '@/components/hud/BottomNav.vue';
import ConversationDrawer from '@/components/hud/ConversationDrawer.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import InteractionFooter from '@/components/hud/InteractionFooter.vue';
import SidePanel from '@/components/hud/SidePanel.vue';
import { useConnectionStore } from '@/stores/connection';
import { usePersistentFlag } from '@/composables/usePersistentFlag';
import { SIDEBAR_COLLAPSED_STORAGE_KEY } from '@/layouts/constants';

const props = withDefaults(
    defineProps<{
        /** Live voice transcription shown in the footer (US-4.1). */
        voicePartial?: string;
        /** Microphone permission denied; voice controls get a tooltip. */
        voiceBlocked?: boolean;
        /** The core is SPEAKING; renders the footer speech controls (US-4.2). */
        speaking?: boolean;
        /** Continuous wake word recognition is running (US-4.4). */
        wakeWordActive?: boolean;
        /** "Resposta falada" is enabled (US-4.2). */
        spokenResponsesEnabled?: boolean;
    }>(),
    { voicePartial: '', voiceBlocked: false, speaking: false, wakeWordActive: false, spokenResponsesEnabled: true },
);

const emit = defineEmits<{
    microphone: [];
    send: [payload: { content: string; source: 'text' | 'voice' }];
    voice: [];
    interrupt: [];
    toggleSpoken: [];
    pauseSpeech: [];
    resumeSpeech: [];
    stopSpeech: [];
}>();

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const connection = useConnectionStore();

/** Sending (text, voice) is blocked while the realtime link is down. */
const sendingDisabled = computed(() => connection.isDisconnected);

/** Tablet (768–1279px) collapse state, persisted between visits. */
const sidebarCollapsed = usePersistentFlag(SIDEBAR_COLLAPSED_STORAGE_KEY);
/** Mobile (<768px) drawer, transient; agents/tasks open it as a section sheet. */
const drawerOpen = ref(false);
const drawerSection = ref<'agents' | 'tasks' | null>(null);
const conversationsOpen = ref(false);

watch(
    () => route.fullPath,
    () => {
        drawerOpen.value = false;
    },
);

const sidebarClasses = computed(() => [
    drawerOpen.value ? 'flex' : 'hidden',
    sidebarCollapsed.value ? 'md:hidden xl:flex' : 'md:flex',
]);

const offsetClasses = computed(() => (sidebarCollapsed.value ? 'xl:pl-80' : 'md:pl-80'));

function toggleSidebar(): void {
    sidebarCollapsed.value = !sidebarCollapsed.value;
}

function toggleDrawer(): void {
    drawerOpen.value = !drawerOpen.value;
    drawerSection.value = null;
}

function openSection(section: 'agents' | 'tasks'): void {
    drawerSection.value = section;
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
    <div class="hud-grid relative min-h-dvh w-full overflow-x-hidden bg-bg text-text" data-testid="hud-layout">
        <header
            class="hud-glass fixed inset-x-0 top-0 z-40 flex h-14 items-center gap-3 border-b border-line px-3 sm:px-5"
            data-testid="layout-header"
        >
            <button
                type="button"
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-sm border border-line text-cyan md:hidden"
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
                type="button"
                class="hidden size-9 shrink-0 items-center justify-center rounded-sm border border-line text-cyan md:inline-flex xl:hidden"
                :aria-label="t('nav.toggleSidebar')"
                :aria-expanded="!sidebarCollapsed"
                data-testid="sidebar-toggle"
                @click="toggleSidebar"
            >
                <span class="block h-4 w-3 border-l-2 border-current"></span>
            </button>
            <slot name="header">
                <AppHeader
                    :voice-unavailable="props.voiceBlocked"
                    :wake-word-active="props.wakeWordActive"
                    :spoken-responses-enabled="props.spokenResponsesEnabled"
                    @microphone="emit('microphone')"
                    @conversations="conversationsOpen = true"
                    @toggle-spoken="emit('toggleSpoken')"
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
            class="hud-glass fixed bottom-0 left-0 top-14 z-30 w-[min(20rem,85vw)] flex-col gap-4 overflow-y-auto border-r border-line p-4 md:w-80"
            :class="sidebarClasses"
            :aria-label="t('layout.sidebar')"
            data-testid="layout-sidebar"
        >
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

            <main
                class="min-w-0 flex-1 px-3 pt-4 pb-40 sm:px-5 md:pb-28"
                :aria-label="t('layout.main')"
                data-testid="layout-main"
            >
                <slot name="main">
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
            <fieldset
                class="hud-glass min-w-0 border-t border-line px-3 py-3 disabled:opacity-60 sm:px-5"
                :disabled="sendingDisabled"
                data-testid="layout-footer-controls"
            >
                <slot name="footer" :sending-disabled="sendingDisabled">
                    <InteractionFooter
                        :disabled="sendingDisabled"
                        :voice-partial="props.voicePartial"
                        :voice-blocked="props.voiceBlocked"
                        :speaking="props.speaking"
                        @send="emit('send', $event)"
                        @voice="emit('voice')"
                        @interrupt="emit('interrupt')"
                        @pause-speech="emit('pauseSpeech')"
                        @resume-speech="emit('resumeSpeech')"
                        @stop-speech="emit('stopSpeech')"
                        @command="onCommand"
                    />
                </slot>
            </fieldset>

            <BottomNav :disabled="sendingDisabled" @select="openSection" @microphone="emit('microphone')" />
        </footer>
    </div>
</template>
