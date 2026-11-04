<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import HeaderMenu from '@/components/hud/HeaderMenu.vue';
import { SHORTCUT_TOOLTIP_MS } from '@/components/hud/constants';
import NotificationCenter from '@/components/hud/NotificationCenter.vue';
import { formatStatusLine } from '@/lib/voice/statusLine';
import { useConnectorsStore } from '@/stores/connectors';
import { useTasksStore } from '@/stores/tasks';
import { useVoiceStore } from '@/stores/voice';
import type { TaskStatus } from '@/types/models';

const TERMINAL_STATUSES: ReadonlySet<TaskStatus> = new Set(['completed', 'failed', 'cancelled']);

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
const tasks = useTasksStore();
const connectors = useConnectorsStore();
const tooltipId = `header-connectors-tooltip-${useId()}`;

const activeTaskCount = computed(() => tasks.tasks.filter((task) => !TERMINAL_STATUSES.has(task.status)).length);

/** `<estado>[ · <N>s][ · <K> tarefa(s)]` (UI-02). */
const statusLine = computed(() =>
    formatStatusLine({
        phase: voice.phase,
        available: voice.available,
        countdownSeconds: voice.countdownSeconds,
        activeTaskCount: activeTaskCount.value,
    }),
);

/** Why voice is unavailable (RF-08a, D5: another tab owns the voice). */
const unavailableReason = computed(() =>
    voice.unavailableReason === null ? undefined : t(`header.unavailableReason.${voice.unavailableReason}`),
);

const isSleeping = computed(() => voice.phase === 'sleeping');

/** Badges are hidden at 0 (UI-03). */
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

/** HH:MM and `DOMINGO · 1 DE NOVEMBRO`, refreshed on every minute boundary (UI-01). */
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

/** `Alt+1` agents, `Alt+2` connectors, `Alt+3` conversations (UI-04). */
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

const iconButton =
    'relative inline-flex size-11 items-center justify-center rounded-sm border border-line text-text hover:border-cyan hover:text-cyan-bright xl:size-9';
const badgeClasses =
    'absolute -right-1 -top-1 min-w-4 rounded-full px-1 text-center font-mono text-[0.55rem] leading-4 text-bg';
</script>

<template>
    <div class="relative flex min-w-0 flex-1 items-center gap-2 sm:gap-3" data-testid="app-header">
        <div class="flex min-w-0 flex-col justify-center gap-1 leading-none" data-testid="header-brand">
            <span class="hud-label truncate font-display text-xs text-cyan hud-glow sm:text-sm md:text-base">
                {{ t('app.name') }}
            </span>
            <span
                class="truncate font-mono text-[0.6rem] tracking-wider"
                :class="voice.available ? 'text-cyan-bright' : 'text-amber'"
                :title="unavailableReason"
                :aria-description="unavailableReason"
                role="status"
                aria-live="polite"
                data-testid="header-status-line"
            >
                {{ statusLine }}
            </span>
        </div>

        <!-- Centered clock and long pt-BR date (UI-01). -->
        <div
            class="pointer-events-none absolute left-1/2 hidden -translate-x-1/2 flex-col items-center font-mono lg:flex"
            role="timer"
            :aria-label="t('header.clock')"
            data-testid="header-clock"
        >
            <time class="text-xl tracking-widest text-cyan-bright hud-glow tabular-nums" data-testid="header-clock-time">
                {{ clockTime }}
            </time>
            <span class="hud-label text-[0.55rem] text-muted" data-testid="header-clock-date">{{ clockDate }}</span>
        </div>

        <!-- Desktop: sleep/wake, mute, agents, connectors, conversations, notifications, menu. -->
        <div class="ml-auto hidden items-center gap-1.5 md:flex" data-testid="header-actions">
            <button
                type="button"
                class="hud-label inline-flex h-11 items-center gap-1.5 rounded-sm border border-line px-2 text-[0.6rem] text-text hover:border-cyan hover:text-cyan-bright disabled:cursor-not-allowed disabled:opacity-40 xl:h-9"
                :disabled="!voice.available"
                :title="unavailableReason"
                data-testid="header-sleep"
                @click="toggleSleep"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
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
                class="hud-label inline-flex h-11 items-center gap-1.5 rounded-sm border px-2 text-[0.6rem] hover:border-cyan hover:text-cyan-bright disabled:cursor-not-allowed disabled:opacity-40 xl:h-9"
                :class="voice.muted ? 'border-danger text-danger' : 'border-line text-text'"
                :aria-pressed="voice.muted"
                :disabled="!voice.available"
                :title="unavailableReason"
                data-testid="header-mic"
                @click="emit('microphone')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                    <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                    <line x1="12" x2="12" y1="19" y2="22" />
                    <line v-if="voice.muted" x1="3" x2="21" y1="3" y2="21" />
                </svg>
                {{ voice.muted ? t('header.unmute') : t('header.mute') }}
            </button>

            <button
                type="button"
                :class="iconButton"
                :aria-label="t('header.agents')"
                :title="`${t('header.agents')} · ${t('header.shortcut', { key: 1 })}`"
                data-testid="header-agents"
                @click="emit('agents')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                    <rect x="4" y="8" width="16" height="12" rx="2" />
                    <path d="M12 4v4" /><circle cx="12" cy="3" r="1" />
                    <circle cx="9" cy="14" r="1" /><circle cx="15" cy="14" r="1" />
                </svg>
                <span v-if="agentsBadge > 0" :class="[badgeClasses, 'bg-cyan']" data-testid="header-agents-badge">
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
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                        <path d="M9 7V3" /><path d="M15 7V3" />
                        <path d="M6 7h12v4a6 6 0 0 1-12 0Z" /><path d="M12 17v4" />
                    </svg>
                    <span v-if="connectorsBadge > 0" :class="[badgeClasses, 'bg-danger']" data-testid="header-connectors-badge">
                        {{ connectorsBadge }}
                    </span>
                </button>
                <div
                    v-show="connectorsTooltipVisible"
                    :id="tooltipId"
                    role="tooltip"
                    class="hud-glass absolute right-0 top-11 z-50 flex w-60 flex-col gap-1 rounded-sm border border-line p-2"
                    data-testid="header-connectors-tooltip"
                >
                    <span class="flex items-center justify-between gap-2">
                        <span class="hud-label text-[0.65rem] text-cyan-bright">{{ t('header.connectors') }}</span>
                        <kbd class="rounded-sm border border-line px-1 font-mono text-[0.55rem] text-muted" data-testid="header-connectors-shortcut">
                            {{ t('header.shortcut', { key: 2 }) }}
                        </kbd>
                    </span>
                    <span
                        v-if="connectorsAttention !== ''"
                        class="font-mono text-[0.6rem] text-danger"
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
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                </svg>
            </button>

            <NotificationCenter />

            <HeaderMenu />
        </div>

        <!-- Mobile: mute + compact menu. -->
        <div class="ml-auto flex items-center gap-1.5 md:hidden" data-testid="header-actions-mobile">
            <button
                type="button"
                class="inline-flex size-11 items-center justify-center rounded-sm border text-text hover:border-cyan hover:text-cyan-bright disabled:cursor-not-allowed disabled:opacity-40"
                :class="voice.muted ? 'border-danger text-danger' : 'border-line'"
                :aria-label="voice.muted ? t('header.unmute') : t('header.mute')"
                :aria-pressed="voice.muted"
                :title="unavailableReason"
                :disabled="!voice.available"
                data-testid="header-mic-mobile"
                @click="emit('microphone')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
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
                        class="hud-label flex min-h-11 items-center gap-2 rounded-sm px-2 py-1.5 text-left text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright disabled:opacity-40"
                        role="menuitem"
                        :disabled="!voice.available"
                        data-testid="header-more-sleep"
                        @click="toggleSleep(); close()"
                    >
                        {{ isSleeping ? t('header.wake') : t('header.sleep') }}
                    </button>
                    <button
                        type="button"
                        class="hud-label flex min-h-11 items-center gap-2 rounded-sm px-2 py-1.5 text-left text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                        role="menuitem"
                        data-testid="header-more-agents"
                        @click="emit('agents'); close()"
                    >
                        {{ t('header.agents') }}
                        <span v-if="agentsBadge > 0" class="ml-auto min-w-4 rounded-full bg-cyan px-1 text-center font-mono text-[0.55rem] text-bg">
                            {{ agentsBadge }}
                        </span>
                    </button>
                    <button
                        type="button"
                        class="hud-label flex min-h-11 items-center gap-2 rounded-sm px-2 py-1.5 text-left text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                        role="menuitem"
                        data-testid="header-more-connectors"
                        @click="openConnectors(); close()"
                    >
                        {{ t('header.connectors') }}
                        <span v-if="connectorsBadge > 0" class="ml-auto min-w-4 rounded-full bg-danger px-1 text-center font-mono text-[0.55rem] text-bg">
                            {{ connectorsBadge }}
                        </span>
                    </button>
                    <button
                        type="button"
                        class="hud-label flex min-h-11 items-center gap-2 rounded-sm px-2 py-1.5 text-left text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
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
