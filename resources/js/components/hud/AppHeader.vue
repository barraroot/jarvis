<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import ConnectionIndicator from '@/components/hud/ConnectionIndicator.vue';
import HeaderMenu from '@/components/hud/HeaderMenu.vue';
import { SHORTCUT_TOOLTIP_MS } from '@/components/hud/constants';
import NotificationCenter from '@/components/hud/NotificationCenter.vue';
import { countActiveTasks, formatStatusLine } from '@/lib/voice/statusLine';
import { useAssistantStore } from '@/stores/assistant';
import { useConnectorsStore } from '@/stores/connectors';
import { useTasksStore } from '@/stores/tasks';
import { useVoiceStore } from '@/stores/voice';

/**
 * `sleep`/`wake` drive the voice window (RF-05), `microphone` toggles the
 * mute (RF-06), `agents`/`conversations` open their panels (`Alt+1`/`Alt+3`).
 */
const emit = defineEmits<{
    sleep: [];
    wake: [];
    microphone: [];
    agents: [];
    conversations: [];
}>();

const { t, locale } = useI18n();
const router = useRouter();
const voice = useVoiceStore();
const assistant = useAssistantStore();
const tasks = useTasksStore();
const connectors = useConnectorsStore();
const tooltipId = `header-connectors-tooltip-${useId()}`;

/** Tasks in `running` or `waiting_approval`: agents badge and status line share the rule (UI-02, UI-03). */
const activeTaskCount = computed(() => countActiveTasks(tasks.tasks));
const waitingApprovalCount = computed(() => tasks.tasks.filter((task) => task.status === 'waiting_approval').length);

/** `<estado>[ · <N>s][ · <K> tarefa(s)]` (UI-03). */
const statusLine = computed(() =>
    formatStatusLine({
        phase: voice.phase,
        available: voice.available,
        countdownSeconds: voice.countdownSeconds,
        activeTaskCount: activeTaskCount.value,
        assistantState: assistant.state,
        waitingApprovalCount: waitingApprovalCount.value,
    }),
);

/** Why voice is unavailable (RF-08a, D5: another tab owns the voice). */
const unavailableReason = computed(() =>
    voice.unavailableReason === null ? undefined : t(`header.unavailableReason.${voice.unavailableReason}`),
);

const isSleeping = computed(() => voice.phase === 'sleeping');

/** Badges are hidden at 0 (UI-02). */
const agentsBadge = computed(() => activeTaskCount.value);
const connectorsBadge = computed(() => connectors.errorCount);

const connectorsAttention = computed(() =>
    connectors.errorCount > 0 ? t('header.connectorsAttention', connectors.errorCount) : '',
);

const connectorsTooltipVisible = ref(false);
let tooltipTimer: ReturnType<typeof setTimeout> | null = null;

function showConnectorsTooltip(): void {
    connectorsTooltipVisible.value = true;
}

function hideConnectorsTooltip(): void {
    connectorsTooltipVisible.value = false;
}

function openConnectors(): void {
    void router.push({ name: 'settings', hash: '#connectors' });
}

function toggleSleep(): void {
    if (isSleeping.value) {
        emit('wake');
    } else {
        emit('sleep');
    }
}

/** HH:MM and `SÁBADO · 7 DE NOVEMBRO`, refreshed on every minute boundary (UI-02). */
const now = ref(new Date());
let clockTimer: ReturnType<typeof setTimeout> | null = null;

const pad = (value: number): string => String(value).padStart(2, '0');

const clockTime = computed(() => `${pad(now.value.getHours())}:${pad(now.value.getMinutes())}`);

const clockDate = computed(() => {
    const weekday = new Intl.DateTimeFormat(locale.value, { weekday: 'long' }).format(now.value);
    const dayMonth = new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'long' }).format(now.value);

    return `${weekday} · ${dayMonth}`.toLocaleUpperCase(locale.value);
});

function scheduleClock(): void {
    const current = new Date();
    const untilNextMinute = 60_000 - (current.getSeconds() * 1000 + current.getMilliseconds());

    clockTimer = setTimeout(() => {
        now.value = new Date();
        scheduleClock();
    }, untilNextMinute);
}

/** `Alt+1` agents, `Alt+2` connectors, `Alt+3` conversations (UI-02). */
function shortcutDigit(event: KeyboardEvent): string | null {
    const match = /^Digit([1-3])$/.exec(event.code);

    if (match !== null) {
        return match[1] ?? null;
    }

    return ['1', '2', '3'].includes(event.key) ? event.key : null;
}

function onKeydown(event: KeyboardEvent): void {
    if (!event.altKey || event.ctrlKey || event.metaKey) {
        return;
    }

    const digit = shortcutDigit(event);

    if (digit === null) {
        return;
    }

    event.preventDefault();

    if (digit === '1') {
        emit('agents');
    } else if (digit === '2') {
        showConnectorsTooltip();

        if (tooltipTimer !== null) {
            clearTimeout(tooltipTimer);
        }

        tooltipTimer = setTimeout(hideConnectorsTooltip, SHORTCUT_TOOLTIP_MS);
        openConnectors();
    } else {
        emit('conversations');
    }
}

onMounted(() => {
    now.value = new Date();
    scheduleClock();
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);

    if (clockTimer !== null) {
        clearTimeout(clockTimer);
    }

    if (tooltipTimer !== null) {
        clearTimeout(tooltipTimer);
    }
});

/** Chamfered actions with corner light (UI-02, UI-24); no rounded corners. */
const textButton =
    'chamfer-sm corner-light label-caps inline-flex h-11 items-center gap-1.5 border border-ocean-600/40 px-3 text-xs text-text-100 hover:text-biolum-200 disabled:cursor-not-allowed disabled:opacity-40 xl:h-10';
const iconButton =
    'chamfer-sm corner-light relative inline-flex size-11 items-center justify-center border border-ocean-600/40 text-text-400 hover:text-biolum-200 xl:size-10';
const badgeClasses = 'absolute right-0.5 top-0.5 min-w-4 px-1 text-center font-mono text-[10px] font-semibold leading-4';
const menuItem =
    'label-caps flex min-h-11 items-center gap-2 px-2 py-1.5 text-left text-[11px] text-text-100 hover:bg-cyan-300/10 hover:text-biolum-200 disabled:opacity-40';
</script>

<template>
    <div class="relative flex min-w-0 flex-1 items-center gap-2 sm:gap-3" data-testid="app-header">
        <div class="flex min-w-0 flex-col justify-center gap-1 leading-none" data-testid="header-brand">
            <span class="glow truncate font-logo text-sm font-bold tracking-[0.32em] text-biolum-200 md:text-base">
                {{ t('app.name') }}
            </span>
            <span class="flex min-w-0 items-center gap-3">
                <span
                    class="label-caps truncate text-[11px] md:text-xs"
                    :class="voice.available ? 'text-text-400' : 'text-amber-400'"
                    :title="unavailableReason"
                    :aria-description="unavailableReason"
                    role="status"
                    aria-live="polite"
                    data-testid="header-status-line"
                >
                    {{ statusLine }}
                </span>
                <ConnectionIndicator />
            </span>
        </div>

        <!-- Centered clock and long pt-BR date in caps (UI-02). -->
        <div
            class="pointer-events-none absolute left-1/2 hidden -translate-x-1/2 flex-col items-center gap-0.5 lg:flex"
            role="timer"
            :aria-label="t('header.clock')"
            data-testid="header-clock"
        >
            <time class="font-mono text-[32px] font-medium leading-none tracking-[0.04em] text-text-100 tabular-nums xl:text-clock" data-testid="header-clock-time">
                {{ clockTime }}
            </time>
            <span class="label-caps text-[11px] text-text-400" data-testid="header-clock-date">{{ clockDate }}</span>
        </div>

        <!-- Desktop: sleep/wake, mute, agents, connectors, conversations, notifications, menu. -->
        <div class="ml-auto hidden items-center gap-2 md:flex" data-testid="header-actions">
            <button
                type="button"
                :class="textButton"
                :disabled="!voice.available"
                :title="unavailableReason"
                data-testid="header-sleep"
                @click="toggleSleep"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-4" aria-hidden="true">
                    <template v-if="isSleeping">
                        <circle cx="12" cy="12" r="4" />
                        <path d="M12 2v2" /><path d="M12 20v2" /><path d="M4.9 4.9l1.4 1.4" />
                        <path d="M17.7 17.7l1.4 1.4" /><path d="M2 12h2" /><path d="M20 12h2" />
                        <path d="M4.9 19.1l1.4-1.4" /><path d="M17.7 6.3l1.4-1.4" />
                    </template>
                    <path v-else d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z" />
                </svg>
                {{ isSleeping ? t('header.wake') : t('header.sleep') }}
            </button>

            <button
                type="button"
                :class="[textButton, voice.muted ? 'border-danger-400/70 text-danger-400' : '']"
                :aria-pressed="voice.muted"
                :disabled="!voice.available"
                :title="unavailableReason"
                data-testid="header-mic"
                @click="emit('microphone')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-4" aria-hidden="true">
                    <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                    <line x1="12" x2="12" y1="19" y2="22" />
                    <line v-if="voice.muted" x1="3" x2="21" y1="3" y2="21" />
                </svg>
                {{ voice.muted ? t('header.unmute') : t('header.mute') }}
            </button>

            <span class="mx-1 h-7 w-px bg-ocean-600/50" aria-hidden="true"></span>

            <button
                type="button"
                :class="iconButton"
                :aria-label="t('header.agents')"
                :title="`${t('header.agents')} · ${t('header.shortcut', { key: 1 })}`"
                data-testid="header-agents"
                @click="emit('agents')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-[18px]" aria-hidden="true">
                    <path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z" />
                    <circle cx="12" cy="12" r="3" />
                </svg>
                <span v-if="agentsBadge > 0" :class="[badgeClasses, 'bg-amber-400 text-abyss-950']" data-testid="header-agents-badge">
                    {{ agentsBadge }}
                </span>
            </button>

            <div class="relative">
                <button
                    type="button"
                    :class="iconButton"
                    :aria-label="t('header.connectors')"
                    :aria-describedby="tooltipId"
                    data-testid="header-connectors"
                    @click="openConnectors"
                    @mouseenter="showConnectorsTooltip"
                    @mouseleave="hideConnectorsTooltip"
                    @focus="showConnectorsTooltip"
                    @blur="hideConnectorsTooltip"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-[18px]" aria-hidden="true">
                        <path d="M9 7V3M15 7V3M7 7h10v4a5 5 0 0 1-10 0zM12 16v5" />
                    </svg>
                    <span v-if="connectorsBadge > 0" :class="[badgeClasses, 'bg-danger-400 text-white']" data-testid="header-connectors-badge">
                        {{ connectorsBadge }}
                    </span>
                </button>
                <div
                    v-show="connectorsTooltipVisible"
                    :id="tooltipId"
                    role="tooltip"
                    class="glass chamfer-sm absolute right-0 top-12 z-50 flex w-64 flex-col gap-1 p-3"
                    data-testid="header-connectors-tooltip"
                >
                    <span class="flex items-center justify-between gap-2">
                        <span class="label-caps text-[11px] text-biolum-200">{{ t('header.connectors') }}</span>
                        <kbd class="border border-ocean-600/60 px-1 font-mono text-[10px] text-text-400" data-testid="header-connectors-shortcut">
                            {{ t('header.shortcut', { key: 2 }) }}
                        </kbd>
                    </span>
                    <span
                        v-if="connectorsAttention !== ''"
                        class="font-mono text-[11px] text-danger-400"
                        data-testid="header-connectors-attention"
                    >
                        {{ connectorsAttention }}
                    </span>
                </div>
            </div>

            <button
                type="button"
                :class="iconButton"
                :aria-label="t('header.conversations')"
                :title="`${t('header.conversations')} · ${t('header.shortcut', { key: 3 })}`"
                data-testid="header-conversations"
                @click="emit('conversations')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-[18px]" aria-hidden="true">
                    <path d="M4 5h16v11H8l-4 4z" />
                </svg>
            </button>

            <NotificationCenter />

            <HeaderMenu />
        </div>

        <!-- Mobile: mute + compact menu. -->
        <div class="ml-auto flex items-center gap-1.5 md:hidden" data-testid="header-actions-mobile">
            <button
                type="button"
                :class="[iconButton, voice.muted ? 'border-danger-400/70 text-danger-400' : '']"
                class="disabled:cursor-not-allowed disabled:opacity-40"
                :aria-label="voice.muted ? t('header.unmute') : t('header.mute')"
                :aria-pressed="voice.muted"
                :title="unavailableReason"
                :disabled="!voice.available"
                data-testid="header-mic-mobile"
                @click="emit('microphone')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-[18px]" aria-hidden="true">
                    <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                    <line x1="12" x2="12" y1="19" y2="22" />
                    <line v-if="voice.muted" x1="3" x2="21" y1="3" y2="21" />
                </svg>
            </button>

            <HeaderMenu id-prefix="header-more" :label="t('header.more')">
                <template #extra="{ close }">
                    <button
                        type="button"
                        :class="menuItem"
                        role="menuitem"
                        :disabled="!voice.available"
                        data-testid="header-more-sleep"
                        @click="toggleSleep(); close()"
                    >
                        {{ isSleeping ? t('header.wake') : t('header.sleep') }}
                    </button>
                    <button
                        type="button"
                        :class="menuItem"
                        role="menuitem"
                        data-testid="header-more-agents"
                        @click="emit('agents'); close()"
                    >
                        {{ t('header.agents') }}
                        <span v-if="agentsBadge > 0" class="ml-auto min-w-4 bg-amber-400 px-1 text-center font-mono text-[10px] text-abyss-950">
                            {{ agentsBadge }}
                        </span>
                    </button>
                    <button
                        type="button"
                        :class="menuItem"
                        role="menuitem"
                        data-testid="header-more-connectors"
                        @click="openConnectors(); close()"
                    >
                        {{ t('header.connectors') }}
                        <span v-if="connectorsBadge > 0" class="ml-auto min-w-4 bg-danger-400 px-1 text-center font-mono text-[10px] text-white">
                            {{ connectorsBadge }}
                        </span>
                    </button>
                    <button
                        type="button"
                        :class="menuItem"
                        role="menuitem"
                        data-testid="header-more-conversations"
                        @click="emit('conversations'); close()"
                    >
                        {{ t('header.conversations') }}
                    </button>
                </template>
            </HeaderMenu>
        </div>
    </div>
</template>
