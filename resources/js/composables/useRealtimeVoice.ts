import { getCurrentInstance, onBeforeUnmount, watch } from 'vue';
import { useWakeWord } from '@/composables/useWakeWord';
import { translate } from '@/i18n';
import {
    isWebRtcSupported,
    type RealtimeTransport,
    RealtimeTransportError,
    WebRtcTransport,
} from '@/lib/voice/RealtimeTransport';
import { RealtimeVoiceSession } from '@/lib/voice/RealtimeVoiceSession';
import { TurnBuffer } from '@/lib/voice/TurnBuffer';
import { acquireVoiceTabLock, type VoiceTabLock } from '@/lib/voice/voiceTabLock';
import { useToastStore } from '@/stores/toast';
import { useVisualizationsStore } from '@/stores/visualizations';
import { useVoiceStore } from '@/stores/voice';
import type { TaskStatus, TaskSummary } from '@/types/models';

/** Countdown tick (RF-03). */
export const COUNTDOWN_TICK_MS = 1000;

/** Refresh rate of the MIC/VOZ gauges (UI-06). */
export const LEVEL_POLL_MS = 100;

const TERMINAL_STATUSES: ReadonlySet<TaskStatus> = new Set(['completed', 'failed', 'cancelled']);

/** Echo-compatible channel; the real one comes from `useRealtimeConnection`. */
export interface MessageChannel {
    listen(event: string, callback: (payload: unknown) => void): unknown;
}

export interface UseRealtimeVoiceOptions {
    createTransport?: () => RealtimeTransport;
    isSupported?: () => boolean;
    tabLock?: VoiceTabLock;
}

/**
 * Orchestrates the continuous voice (RF-01..RF-15): local wake word →
 * CT-01 → Realtime session → "Pode falar."; a listening window whose
 * countdown pauses while someone speaks and restarts on every turn; expiry
 * back to `sleeping` unless a voice task is still running; Dormir/Acordar;
 * Mutar (mic and wake word, output kept); task announcements by voice or by
 * toast while sleeping; one voice-owning tab; credential renewal when the
 * transport drops. Without Realtime the HUD stays text-only, with no Web
 * Speech conversation nor `speechSynthesis`.
 */
export function useRealtimeVoice(options: UseRealtimeVoiceOptions = {}) {
    const voice = useVoiceStore();
    const visualizations = useVisualizationsStore();
    const toasts = useToastStore();
    const createTransport = options.createTransport ?? (() => new WebRtcTransport());
    const lock = options.tabLock ?? acquireVoiceTabLock();

    let session: RealtimeVoiceSession | null = null;
    let turns: TurnBuffer | null = null;
    let countdownTimer: ReturnType<typeof setInterval> | null = null;
    let levelTimer: ReturnType<typeof setInterval> | null = null;
    let connecting = false;

    if (!(options.isSupported ?? isWebRtcSupported)()) {
        voice.markUnavailable('unsupported');
    }

    const wakeWord = useWakeWord({
        active: () => voice.available && !voice.muted && voice.phase === 'sleeping' && !voice.sessionOpen && !voice.opening && !connecting && lock.held.value,
        onWake: () => void openWindow(),
    });

    watch(
        () => lock.held.value,
        (held) => {
            if (!held && voice.available) {
                voice.markUnavailable('other_tab');
            } else if (held && voice.unavailableReason === 'other_tab') {
                voice.markAvailable();
            }
        },
        { immediate: true },
    );

    function resetCountdown(): void {
        if (session !== null) {
            voice.setCountdown(voice.listeningWindowSeconds);
        }
    }

    /** The countdown only runs while the window is open and nobody speaks (D8). */
    function tick(): void {
        if (session === null || voice.phase !== 'listening' || voice.countdownSeconds === null) {
            return;
        }

        const next = voice.countdownSeconds - 1;

        if (next > 0) {
            voice.setCountdown(next);

            return;
        }

        if (voice.hasActiveVoiceTask) {
            resetCountdown();

            return;
        }

        void closeWindow();
    }

    function stopTimers(): void {
        if (countdownTimer !== null) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }

        if (levelTimer !== null) {
            clearInterval(levelTimer);
            levelTimer = null;
        }
    }

    function startTimers(): void {
        stopTimers();
        countdownTimer = setInterval(tick, COUNTDOWN_TICK_MS);
        levelTimer = setInterval(() => {
            if (session !== null) {
                voice.setLevels(session.levels());
            }
        }, LEVEL_POLL_MS);
    }

    function buildSession(transport: RealtimeTransport): RealtimeVoiceSession {
        return new RealtimeVoiceSession(transport, {
            onPhase: (phase) => {
                voice.setPhase(phase);

                if (phase === 'listening') {
                    voice.setSpokenWordCount(voice.assistantWords.length);
                }
            },
            onUserCaption: (text) => voice.setUserCaption(text),
            onAssistantStart: () => voice.setAssistantText(''),
            onAssistantDelta: (delta) => voice.appendAssistantText(delta),
            onTurn: (turn) => turns?.push(turn),
            onUsage: (responseId, usage) => void voice.reportUsage(responseId, usage),
            onStartTask: (call) => voice.startTask(call.request, call.followUp ? (visualizations.current?.id ?? null) : null),
            onActivity: resetCountdown,
            onClose: () => void reconnect(),
        });
    }

    function notifyTransportError(error: unknown): void {
        if (error instanceof RealtimeTransportError && error.code === 'mic_denied') {
            voice.markUnavailable('mic_denied');
            toasts.push(translate('voice.permissionDenied'), { variant: 'danger' });
        } else if (error instanceof RealtimeTransportError && error.code === 'unsupported') {
            voice.markUnavailable('unsupported');
            toasts.push(translate('voice.errors.unsupported'), { variant: 'warning' });
        } else {
            toasts.push(translate('voice.errors.connectionFailed'), { variant: 'danger' });
        }
    }

    /** Connects a fresh transport with the current grant; null when it failed. */
    async function connect(): Promise<RealtimeVoiceSession | null> {
        const grant = voice.grant;

        if (grant === null) {
            return null;
        }

        const next = buildSession(createTransport());

        try {
            await next.start(grant.client_secret);
        } catch (error) {
            notifyTransportError(error);

            return null;
        }

        session = next;
        next.setMicEnabled(!voice.muted);

        return next;
    }

    /**
     * Opens the listening window (RF-01, RF-05 "Acordar"): CT-01, WebRTC and
     * the "Pode falar." greeting (RF-02). Any failure falls back to
     * `sleeping` with a pt-BR toast (RF-08).
     */
    async function openWindow(): Promise<void> {
        if (connecting || session !== null || !voice.available || !lock.held.value) {
            return;
        }

        connecting = true;

        try {
            const grant = await voice.openSession();

            if (grant === null) {
                return;
            }

            const sessionId = grant.voice_session_id;

            turns = new TurnBuffer({ flush: (batch) => voice.flushTurns(batch, sessionId) });

            const opened = await connect();

            if (opened === null) {
                await turns.close();
                turns = null;
                voice.closeSession();

                return;
            }

            voice.setPhase('listening');
            voice.clearCaptions();
            resetCountdown();
            startTimers();
            opened.say(translate('voice.greeting'));
        } finally {
            connecting = false;
        }
    }

    /** Ends the window: closes Realtime, persists pending turns, back to `sleeping` (RF-04, RF-05). */
    async function closeWindow(): Promise<void> {
        stopTimers();

        const closing = session;
        const buffer = turns;

        session = null;
        turns = null;
        closing?.close();
        voice.closeSession();
        await buffer?.close();
    }

    /** Transport dropped with the window open: renew the credential of the same session (CT-01). */
    async function reconnect(): Promise<void> {
        if (session === null || connecting) {
            return;
        }

        session = null;
        connecting = true;

        try {
            const grant = await voice.renewSession();

            if (grant === null || (await connect()) === null) {
                await closeWindow();
            }
        } finally {
            connecting = false;
        }
    }

    /** "Dormir" (RF-05): the wake word keeps listening afterwards. */
    function sleep(): Promise<void> {
        return closeWindow();
    }

    /** "Acordar" (RF-05): opens the window without the wake word. */
    function wake(): Promise<void> {
        return openWindow();
    }

    /** "Mutar microfone" (RF-06): stops the mic for Realtime and the wake word; output keeps playing. */
    function toggleMute(): void {
        voice.setMuted(!voice.muted);
        session?.setMicEnabled(!voice.muted);
    }

    /** RF-13 / RF-13a / RF-15: narrates (or toasts) the outcome of tasks started by voice. */
    function onTaskUpdated(task: TaskSummary): void {
        const previous = voice.voiceTasks[String(task.id)];

        if (!voice.trackVoiceTask(task) || previous === task.status) {
            return;
        }

        if (TERMINAL_STATUSES.has(task.status)) {
            if (session !== null) {
                session.announce(task.voice_summary ?? translate(`voice.taskStatus.${task.status}`, { title: task.title }));
            } else {
                toasts.push(translate(`voice.taskStatus.${task.status}`, { title: task.title }), {
                    variant: task.status === 'completed' ? 'success' : task.status === 'failed' ? 'danger' : 'info',
                });
            }

            return;
        }

        if (task.status === 'waiting_approval') {
            const notice = translate('voice.approvalPending', { title: task.title });

            if (session !== null) {
                session.say(notice);
            } else {
                toasts.push(notice, { variant: 'warning' });
            }
        }
    }

    function bind(channel: MessageChannel): void {
        channel.listen('.task.updated', (payload: unknown) => {
            const task = (payload as { task?: unknown } | null)?.task;

            if (typeof task === 'object' && task !== null && 'id' in task && 'status' in task) {
                onTaskUpdated(task as TaskSummary);
            }
        });
    }

    function dispose(): void {
        void closeWindow();
        wakeWord.dispose();
        lock.release();
    }

    if (getCurrentInstance() !== null) {
        onBeforeUnmount(dispose);
    }

    return { voice, wakeWord, openWindow, closeWindow, sleep, wake, toggleMute, onTaskUpdated, bind, dispose };
}
