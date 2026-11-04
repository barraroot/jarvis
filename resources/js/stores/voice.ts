import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { translate } from '@/i18n';
import { API_PREFIX, HttpError, http } from '@/lib/http';
import { splitWords } from '@/lib/voice/captions';
import { useTasksStore } from '@/stores/tasks';
import { useToastStore } from '@/stores/toast';
import type { RealtimeUsage, TaskStatus, TaskSummary, VoiceSessionGrant, VoiceTurn, VoiceUsageTotals } from '@/types/models';

/**
 * Local voice phase, kept apart from the server-driven `assistant` states:
 * `sleeping` waits for the wake word, `listening` is the open window,
 * `user_speaking` / `speaking` are the two sides of a turn (UI-05).
 */
export type VoicePhase = 'sleeping' | 'listening' | 'user_speaking' | 'speaking';

/** Why the HUD runs text-only (RF-08, RF-08a, D5). */
export type VoiceUnavailableReason = 'no_realtime' | 'unsupported' | 'other_tab' | 'mic_denied';

/** Maximum turns per CT-10 request (D2). */
export const VOICE_TURN_BATCH_LIMIT = 20;

/** Window length used until CT-01 delivers `listening_window_seconds` (RF-03). */
export const DEFAULT_LISTENING_WINDOW_SECONDS = 60;

const TERMINAL_STATUSES: ReadonlySet<TaskStatus> = new Set(['completed', 'failed', 'cancelled']);

interface GrantEnvelope {
    data: VoiceSessionGrant;
}

interface UsageEnvelope {
    data: VoiceUsageTotals;
}

interface TaskEnvelope {
    data: TaskSummary;
}

function clampLevel(level: number): number {
    return Number.isFinite(level) ? Math.min(1, Math.max(0, level)) : 0;
}

function messageFor(error: unknown, fallbackKey: string): string {
    if (error instanceof HttpError && error.serverMessage !== null && error.serverMessage !== '') {
        return error.serverMessage;
    }

    return translate(fallbackKey);
}

/**
 * Realtime voice state (RF-01..RF-08a, RF-10a, RF-11, RF-21): phase, the
 * open voice session, the countdown, MIC/VOZ levels and captions, plus the
 * HTTP actions of CT-01 (open/renew), CT-02 (voice tasks), CT-07 (usage) and
 * CT-10 (turns). Failures surface as pt-BR toasts and never throw, so the
 * orchestration (`useRealtimeVoice`) can fall back to text-only.
 */
export const useVoiceStore = defineStore('voice', () => {
    const phase = ref<VoicePhase>('sleeping');
    const unavailableReason = ref<VoiceUnavailableReason | null>(null);
    const muted = ref(false);
    const countdownSeconds = ref<number | null>(null);
    const listeningWindowSeconds = ref(DEFAULT_LISTENING_WINDOW_SECONDS);
    const micLevel = ref(0);
    const outputLevel = ref(0);
    const userCaption = ref('');
    /** Raw JARVIS transcript; deltas may split a word, so words are derived from it (UI-07). */
    const assistantText = ref('');
    const assistantWords = ref<string[]>([]);
    const spokenWordCount = ref(0);
    const voiceSessionId = ref<number | null>(null);
    const conversationId = ref<number | null>(null);
    const grant = ref<VoiceSessionGrant | null>(null);
    const opening = ref(false);
    /** Status of every task started by voice in this page lifetime (RF-04a, RF-13). */
    const voiceTasks = ref<Record<string, TaskStatus>>({});

    const available = computed(() => unavailableReason.value === null);
    const sessionOpen = computed(() => voiceSessionId.value !== null);
    const hasActiveVoiceTask = computed(() => Object.values(voiceTasks.value).some((status) => !TERMINAL_STATUSES.has(status)));
    const activeVoiceTaskCount = computed(() => Object.values(voiceTasks.value).filter((status) => !TERMINAL_STATUSES.has(status)).length);

    function setPhase(next: VoicePhase): void {
        phase.value = next;
    }

    function markUnavailable(reason: VoiceUnavailableReason): void {
        unavailableReason.value = reason;
    }

    function markAvailable(): void {
        unavailableReason.value = null;
    }

    function setMuted(value: boolean): void {
        muted.value = value;

        if (value) {
            micLevel.value = 0;
        }
    }

    function setLevels(levels: { mic?: number; output?: number }): void {
        if (levels.mic !== undefined) {
            micLevel.value = muted.value ? 0 : clampLevel(levels.mic);
        }

        if (levels.output !== undefined) {
            outputLevel.value = clampLevel(levels.output);
        }
    }

    function setCountdown(seconds: number | null): void {
        countdownSeconds.value = seconds === null ? null : Math.max(0, Math.round(seconds));
    }

    function setUserCaption(text: string): void {
        userCaption.value = text;
    }

    /** Replaces the JARVIS caption; no word is spoken yet (UI-07). */
    function setAssistantText(text: string): void {
        assistantText.value = text;
        assistantWords.value = splitWords(text);
        spokenWordCount.value = 0;
    }

    function appendAssistantText(delta: string): void {
        assistantText.value += delta;
        assistantWords.value = splitWords(assistantText.value);
    }

    function setSpokenWordCount(count: number): void {
        spokenWordCount.value = Math.min(assistantWords.value.length, Math.max(0, Math.floor(count)));
    }

    function clearCaptions(): void {
        userCaption.value = '';
        assistantText.value = '';
        assistantWords.value = [];
        spokenWordCount.value = 0;
    }

    function applyGrant(data: VoiceSessionGrant): void {
        grant.value = data;
        voiceSessionId.value = data.voice_session_id;
        conversationId.value = data.conversation_id;
        listeningWindowSeconds.value = data.listening_window_seconds;
        unavailableReason.value = null;
    }

    function notifySessionError(error: unknown): void {
        const toasts = useToastStore();

        if (!(error instanceof HttpError)) {
            toasts.push(translate('voice.errors.sessionFailed'), { variant: 'danger' });

            return;
        }

        switch (error.status) {
            case 402:
                toasts.push(messageFor(error, 'voice.errors.costLimit'), { variant: 'warning' });
                break;
            case 409:
                markUnavailable('no_realtime');
                toasts.push(messageFor(error, 'voice.errors.realtimeUnavailable'), { variant: 'warning' });
                break;
            case 429:
                toasts.push(translate('voice.errors.tooManyRequests', { seconds: error.retryAfter ?? 60 }), { variant: 'warning' });
                break;
            case 503:
                toasts.push(messageFor(error, 'voice.errors.providerFailed'), { variant: 'danger' });
                break;
            default:
                toasts.push(translate('voice.errors.sessionFailed'), { variant: 'danger' });
        }
    }

    async function requestGrant(body: Record<string, unknown>): Promise<VoiceSessionGrant | null> {
        opening.value = true;

        try {
            const response = await http.post<GrantEnvelope>(`${API_PREFIX}/voice/sessions`, body, { skipTooManyRequestsHandler: true });

            applyGrant(response.data);

            return response.data;
        } catch (error) {
            notifySessionError(error);

            return null;
        } finally {
            opening.value = false;
        }
    }

    /** CT-01 without `voice_session_id`: a new wake session and conversation (RF-21). */
    function openSession(): Promise<VoiceSessionGrant | null> {
        return requestGrant({ locale: 'pt-BR' });
    }

    /** CT-01 with the open `voice_session_id`: a fresh credential for the same conversation (RF-10). */
    async function renewSession(): Promise<VoiceSessionGrant | null> {
        if (voiceSessionId.value === null) {
            return openSession();
        }

        return requestGrant({ locale: 'pt-BR', voice_session_id: voiceSessionId.value });
    }

    /** Forgets the voice session locally; the backend expires it by inactivity (D11). */
    function closeSession(): void {
        grant.value = null;
        voiceSessionId.value = null;
        conversationId.value = null;
        countdownSeconds.value = null;
        phase.value = 'sleeping';
        micLevel.value = 0;
        outputLevel.value = 0;
        clearCaptions();
    }

    /** CT-07: reports the `usage` of a `response.done` (RF-10a). */
    async function reportUsage(responseId: string, usage: RealtimeUsage): Promise<VoiceUsageTotals | null> {
        if (voiceSessionId.value === null) {
            return null;
        }

        try {
            const response = await http.post<UsageEnvelope>(`${API_PREFIX}/voice/sessions/${voiceSessionId.value}/usage`, {
                response_id: responseId,
                usage,
            });

            return response.data;
        } catch (error) {
            if (error instanceof HttpError && error.status === 422) {
                useToastStore().push(messageFor(error, 'voice.errors.usageCeiling'), { variant: 'warning' });
            }

            return null;
        }
    }

    /**
     * CT-10: persists transcribed turns in batches of at most
     * {@link VOICE_TURN_BATCH_LIMIT}, in the given order (RF-21). Resolves
     * false when a batch fails; later batches are not sent.
     */
    async function flushTurns(turns: VoiceTurn[], sessionId: number | null = voiceSessionId.value): Promise<boolean> {
        if (sessionId === null || turns.length === 0) {
            return turns.length === 0;
        }

        for (let start = 0; start < turns.length; start += VOICE_TURN_BATCH_LIMIT) {
            try {
                await http.post(`${API_PREFIX}/voice/sessions/${sessionId}/turns`, {
                    turns: turns.slice(start, start + VOICE_TURN_BATCH_LIMIT),
                });
            } catch {
                return false;
            }
        }

        return true;
    }

    /** Records the status of a voice task; false when the task was not started by voice. */
    function trackVoiceTask(task: Pick<TaskSummary, 'id' | 'status'>): boolean {
        const id = String(task.id);

        if (!(id in voiceTasks.value)) {
            return false;
        }

        voiceTasks.value = { ...voiceTasks.value, [id]: task.status };

        return true;
    }

    function isVoiceTask(id: string | number): boolean {
        return String(id) in voiceTasks.value;
    }

    /**
     * CT-02: starts a background task always bound to the open voice session
     * (RF-11), optionally following up an open visualization (RF-19). Resolves
     * null on failure, after a pt-BR toast, so the caller can tell the model.
     */
    async function startTask(request: string, visualizationId: number | null = null): Promise<TaskSummary | null> {
        const toasts = useToastStore();

        if (voiceSessionId.value === null) {
            toasts.push(translate('voice.errors.sessionInactive'), { variant: 'warning' });

            return null;
        }

        try {
            const response = await http.post<TaskEnvelope>(`${API_PREFIX}/voice/tasks`, {
                voice_session_id: voiceSessionId.value,
                request,
                visualization_id: visualizationId,
            });

            voiceTasks.value = { ...voiceTasks.value, [String(response.data.id)]: response.data.status };
            useTasksStore().applyEvent(response.data);

            return response.data;
        } catch (error) {
            if (error instanceof HttpError && (error.status === 404 || error.status === 422)) {
                toasts.push(messageFor(error, 'voice.errors.sessionInactive'), { variant: 'warning' });
            } else if (error instanceof HttpError && error.status === 402) {
                toasts.push(messageFor(error, 'voice.errors.costLimit'), { variant: 'warning' });
            } else if (!(error instanceof HttpError && error.status === 429)) {
                toasts.push(translate('voice.errors.taskFailed'), { variant: 'danger' });
            }

            return null;
        }
    }

    return {
        phase,
        unavailableReason,
        available,
        muted,
        countdownSeconds,
        listeningWindowSeconds,
        micLevel,
        outputLevel,
        userCaption,
        assistantText,
        assistantWords,
        spokenWordCount,
        voiceSessionId,
        conversationId,
        grant,
        opening,
        voiceTasks,
        sessionOpen,
        hasActiveVoiceTask,
        activeVoiceTaskCount,
        setPhase,
        markUnavailable,
        markAvailable,
        setMuted,
        setLevels,
        setCountdown,
        setUserCaption,
        setAssistantText,
        appendAssistantText,
        setSpokenWordCount,
        clearCaptions,
        openSession,
        renewSession,
        closeSession,
        reportUsage,
        flushTurns,
        trackVoiceTask,
        isVoiceTask,
        startTask,
    };
});
