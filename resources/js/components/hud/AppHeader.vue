<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import Clock from '@/components/ui/Clock.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useAssistantStore } from '@/stores/assistant';
import { useAuthStore } from '@/stores/auth';
import { useConnectorsStore } from '@/stores/connectors';
import { useNotificationsStore } from '@/stores/notifications';

const props = withDefaults(
    defineProps<{
        /** Microphone permission denied; the mic buttons get a tooltip. */
        voiceUnavailable?: boolean;
        /** Continuous wake word recognition is running (US-4.4). */
        wakeWordActive?: boolean;
        /** "Resposta falada" is enabled (US-4.2). */
        spokenResponsesEnabled?: boolean;
    }>(),
    { voiceUnavailable: false, wakeWordActive: false, spokenResponsesEnabled: true },
);

const emit = defineEmits<{
    microphone: [];
    conversations: [];
    toggleSpoken: [];
}>();

const { t } = useI18n();
const assistant = useAssistantStore();
const auth = useAuthStore();
const notifications = useNotificationsStore();
const connectors = useConnectorsStore();

const isSleeping = computed(() => assistant.state === 'SLEEPING');

const voiceSupported =
    typeof window !== 'undefined' && ('SpeechRecognition' in window || 'webkitSpeechRecognition' in window);

const micTooltip = computed(() => {
    if (props.voiceUnavailable) {
        return t('header.voiceDenied');
    }

    return voiceSupported ? undefined : t('header.voiceUnsupported');
});

const spokenLabel = computed(() =>
    props.spokenResponsesEnabled ? t('header.spokenResponsesOn') : t('header.spokenResponsesOff'),
);

const hasConnectors = computed(() => connectors.connectedCount > 0 || connectors.errorCount > 0);

/** Mobile collapses everything into a "more" dropdown; desktop keeps its own user menu. */
const moreOpen = ref(false);
const userOpen = ref(false);
const rootRef = ref<HTMLElement | null>(null);

function closeMenus(): void {
    moreOpen.value = false;
    userOpen.value = false;
}

function toggleSleep(): void {
    if (isSleeping.value) {
        assistant.wake();
    } else {
        assistant.sleep();
    }

    closeMenus();
}

function onDocumentClick(event: MouseEvent): void {
    if (rootRef.value !== null && !rootRef.value.contains(event.target as Node)) {
        closeMenus();
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        closeMenus();
    }
}

async function logout(): Promise<void> {
    closeMenus();
    await auth.logout();
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div ref="rootRef" class="relative flex min-w-0 flex-1 items-center gap-2 sm:gap-3" data-testid="app-header">
        <div class="flex min-w-0 flex-col justify-center leading-none" data-testid="header-brand">
            <span class="hud-label truncate font-display text-xs text-cyan hud-glow sm:text-sm md:text-base">
                {{ t('app.name') }}
            </span>
            <span class="hud-label hidden truncate text-[0.5rem] text-muted md:block">
                {{ t('app.tagline') }}
            </span>
        </div>

        <div class="shrink-0" data-testid="header-status">
            <StatusBadge :status="assistant.state" />
        </div>

        <span
            v-if="props.wakeWordActive"
            class="hud-label inline-flex items-center gap-1.5 rounded-sm border border-cyan/50 px-2 py-1 font-mono text-[0.6rem] text-cyan-bright"
            :aria-label="t('header.wakeWordActive')"
            data-testid="header-wake-word"
        >
            <span class="size-1.5 animate-pulse rounded-full bg-cyan-bright"></span>
            {{ t('header.wakeWordActive') }}
        </span>

        <!-- Desktop: clock, sleep, microphone, conversations, notifications, connectors, settings, user. -->
        <div class="ml-auto hidden items-center gap-1.5 md:flex" data-testid="header-actions">
            <div class="hidden lg:block">
                <Clock />
            </div>

            <button
                type="button"
                class="inline-flex size-9 items-center justify-center rounded-sm border border-line text-text hover:border-cyan hover:text-cyan-bright"
                :aria-label="isSleeping ? t('header.wake') : t('header.sleep')"
                data-testid="header-sleep"
                @click="toggleSleep"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                    <circle v-if="isSleeping" cx="12" cy="12" r="4" />
                    <template v-if="isSleeping">
                        <path d="M12 2v2" /><path d="M12 20v2" /><path d="M4.9 4.9l1.4 1.4" />
                        <path d="M17.7 17.7l1.4 1.4" /><path d="M2 12h2" /><path d="M20 12h2" />
                        <path d="M4.9 19.1l1.4-1.4" /><path d="M17.7 6.3l1.4-1.4" />
                    </template>
                    <path v-else d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z" />
                </svg>
            </button>

            <button
                type="button"
                class="inline-flex size-9 items-center justify-center rounded-sm border border-line text-text hover:border-cyan hover:text-cyan-bright disabled:cursor-not-allowed disabled:opacity-40"
                :aria-label="t('nav.microphone')"
                :title="micTooltip"
                :disabled="!voiceSupported || props.voiceUnavailable"
                data-testid="header-mic"
                @click="emit('microphone')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                    <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                    <line x1="12" x2="12" y1="19" y2="22" />
                </svg>
            </button>

            <button
                type="button"
                class="inline-flex size-9 items-center justify-center rounded-sm border border-line text-text hover:border-cyan hover:text-cyan-bright"
                :class="props.spokenResponsesEnabled ? 'text-cyan-bright' : 'opacity-50'"
                :aria-label="spokenLabel"
                :aria-pressed="props.spokenResponsesEnabled"
                data-testid="header-spoken"
                @click="emit('toggleSpoken')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                    <path d="M11 5 6 9H2v6h4l5 4V5z" />
                    <path d="M15.54 8.46a5 5 0 0 1 0 7.07" />
                    <path d="M19.07 4.93a10 10 0 0 1 0 14.14" />
                </svg>
            </button>

            <button
                type="button"
                class="inline-flex size-9 items-center justify-center rounded-sm border border-line text-text hover:border-cyan hover:text-cyan-bright"
                :aria-label="t('header.conversations')"
                data-testid="header-conversations"
                @click="emit('conversations')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                </svg>
            </button>

            <button
                type="button"
                class="relative inline-flex size-9 items-center justify-center rounded-sm border border-line text-text hover:border-cyan hover:text-cyan-bright"
                :aria-label="t('header.notifications')"
                data-testid="header-notifications"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                    <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                    <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
                </svg>
                <span
                    v-if="notifications.unreadCount > 0"
                    class="absolute -right-1 -top-1 min-w-4 rounded-full bg-danger px-1 text-center font-mono text-[0.55rem] leading-4 text-bg"
                    data-testid="header-notifications-count"
                >
                    {{ notifications.unreadCount }}
                </span>
            </button>

            <span
                v-if="hasConnectors"
                class="hud-label inline-flex items-center gap-1 rounded-sm border border-line px-2 py-1.5 font-mono text-[0.6rem]"
                :aria-label="t('header.connectors')"
                data-testid="header-connectors"
            >
                <span class="size-1.5 rounded-full bg-cyan"></span>
                <span class="text-cyan-bright">{{ connectors.connectedCount }}</span>
                <span class="text-muted">/</span>
                <span class="text-danger">{{ connectors.errorCount }}</span>
            </span>

            <RouterLink
                :to="{ name: 'settings' }"
                class="inline-flex size-9 items-center justify-center rounded-sm border border-line text-text hover:border-cyan hover:text-cyan-bright"
                :aria-label="t('header.settings')"
                data-testid="header-settings"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                    <line x1="4" x2="4" y1="21" y2="14" /><line x1="4" x2="4" y1="10" y2="3" />
                    <line x1="12" x2="12" y1="21" y2="12" /><line x1="12" x2="12" y1="8" y2="3" />
                    <line x1="20" x2="20" y1="21" y2="16" /><line x1="20" x2="20" y1="12" y2="3" />
                    <line x1="1" x2="7" y1="14" y2="14" /><line x1="9" x2="15" y1="8" y2="8" />
                    <line x1="17" x2="23" y1="16" y2="16" />
                </svg>
            </RouterLink>

            <div class="relative">
                <button
                    type="button"
                    class="inline-flex h-9 max-w-40 items-center gap-2 rounded-sm border border-line px-2 text-text hover:border-cyan hover:text-cyan-bright"
                    :aria-label="t('header.userMenu')"
                    :aria-expanded="userOpen"
                    data-testid="header-user"
                    @click="userOpen = !userOpen"
                >
                    <span class="hud-label truncate font-mono text-[0.6rem]">{{ auth.user?.name ?? '' }}</span>
                </button>
                <div
                    v-if="userOpen"
                    class="hud-glass absolute right-0 top-11 z-50 flex w-56 flex-col gap-1 rounded-sm border border-line p-2"
                    data-testid="header-user-menu"
                >
                    <div class="hud-label truncate px-2 py-1 text-[0.6rem] text-cyan">{{ auth.user?.name ?? '' }}</div>
                    <div class="truncate border-b border-line/70 px-2 pb-2 font-mono text-[0.6rem] text-muted">
                        {{ auth.user?.email ?? '' }}
                    </div>
                    <button
                        type="button"
                        class="hud-label flex items-center gap-2 rounded-sm px-2 py-1.5 text-left text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                        data-testid="header-logout"
                        @click="logout"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-3.5" aria-hidden="true">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                            <polyline points="16 17 21 12 16 7" />
                            <line x1="21" x2="9" y1="12" y2="12" />
                        </svg>
                        {{ t('header.logout') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile: status + microphone + more menu. -->
        <div class="ml-auto flex items-center gap-1.5 md:hidden" data-testid="header-actions-mobile">
            <button
                type="button"
                class="inline-flex size-9 items-center justify-center rounded-sm border border-line text-text hover:border-cyan hover:text-cyan-bright disabled:cursor-not-allowed disabled:opacity-40"
                :aria-label="t('nav.microphone')"
                :title="micTooltip"
                :disabled="!voiceSupported || props.voiceUnavailable"
                data-testid="header-mic-mobile"
                @click="emit('microphone')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                    <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                    <line x1="12" x2="12" y1="19" y2="22" />
                </svg>
            </button>

            <button
                type="button"
                class="inline-flex size-9 items-center justify-center rounded-sm border border-line text-text hover:border-cyan hover:text-cyan-bright"
                :aria-label="t('header.more')"
                :aria-expanded="moreOpen"
                data-testid="header-more"
                @click="moreOpen = !moreOpen"
            >
                <svg viewBox="0 0 24 24" fill="currentColor" class="size-4" aria-hidden="true">
                    <circle cx="5" cy="12" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="19" cy="12" r="1.6" />
                </svg>
            </button>

            <div
                v-if="moreOpen"
                class="hud-glass absolute right-0 top-11 z-50 flex w-60 flex-col gap-1 rounded-sm border border-line p-2"
                data-testid="header-more-menu"
            >
                <div class="truncate border-b border-line/70 px-2 pb-2 font-mono text-[0.6rem] text-muted">
                    {{ auth.user?.name ?? '' }}
                </div>
                <button
                    type="button"
                    class="hud-label flex items-center gap-2 rounded-sm px-2 py-1.5 text-left text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                    data-testid="header-more-sleep"
                    @click="toggleSleep"
                >
                    {{ isSleeping ? t('header.wake') : t('header.sleep') }}
                </button>
                <button
                    type="button"
                    class="hud-label flex items-center gap-2 rounded-sm px-2 py-1.5 text-left text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                    data-testid="header-more-spoken"
                    @click="emit('toggleSpoken'); closeMenus()"
                >
                    {{ t('header.spokenResponses') }}
                </button>
                <button
                    type="button"
                    class="hud-label flex items-center gap-2 rounded-sm px-2 py-1.5 text-left text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                    data-testid="header-more-conversations"
                    @click="emit('conversations'); closeMenus()"
                >
                    {{ t('header.conversations') }}
                </button>
                <button
                    type="button"
                    class="hud-label flex items-center gap-2 rounded-sm px-2 py-1.5 text-left text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                    data-testid="header-more-notifications"
                    @click="closeMenus"
                >
                    {{ t('header.notifications') }}
                    <span
                        v-if="notifications.unreadCount > 0"
                        class="ml-auto min-w-4 rounded-full bg-danger px-1 text-center font-mono text-[0.55rem] text-bg"
                    >
                        {{ notifications.unreadCount }}
                    </span>
                </button>
                <RouterLink
                    :to="{ name: 'settings' }"
                    class="hud-label flex items-center gap-2 rounded-sm px-2 py-1.5 text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                    data-testid="header-more-settings"
                    @click="closeMenus"
                >
                    {{ t('header.settings') }}
                </RouterLink>
                <button
                    type="button"
                    class="hud-label flex items-center gap-2 rounded-sm px-2 py-1.5 text-left text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                    data-testid="header-more-logout"
                    @click="logout"
                >
                    {{ t('header.logout') }}
                </button>
            </div>
        </div>
    </div>
</template>
