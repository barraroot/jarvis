import { i18n } from '@/i18n';
import { resolveCoreFrame } from '@/lib/hud/coreFrame';
import type { VoicePhase } from '@/stores/voice';
import type { AssistantState, TaskStatus } from '@/types/models';

/** Separator between the parts of the header status line (UI-03). */
export const STATUS_LINE_SEPARATOR = ' · ';

/** `<estado>` of the status line: one of the 9 labels of UI-03. */
export type StatusLineState =
    | VoicePhase
    | 'unavailable'
    | 'thinking'
    | 'executing'
    | 'waitingApproval'
    | 'error';

export interface StatusLineInput {
    phase: VoicePhase;
    /** False when the HUD runs text-only (RF-08a, D5). */
    available: boolean;
    /** Seconds left in the listening window; null while it is paused or closed (D8). */
    countdownSeconds: number | null;
    /** Tasks in `running` or `waiting_approval` (UI-03); `queued`/`pending` do not count. */
    activeTaskCount: number;
    /** Server assistant state; defaults to `IDLE` (voice phase decides). */
    assistantState?: AssistantState;
    /** Tasks in `waiting_approval`; at least one shows `Aguardando aprovação`. */
    waitingApprovalCount?: number;
}

export interface StatusLineParts {
    state: StatusLineState;
    /** Only while the window is open and nobody is speaking. */
    seconds: number | null;
    /** Only with at least one counted task, and never while sleeping or while the operator speaks. */
    tasks: number | null;
}

/** Task statuses counted by the status line and the agents badge (UI-02, UI-03). */
const COUNTED_TASK_STATUSES: ReadonlySet<TaskStatus> = new Set(['running', 'waiting_approval']);

/** Counts the tasks in `running` or `waiting_approval` — the `<K>` of UI-03. */
export function countActiveTasks(tasks: ReadonlyArray<{ status: TaskStatus }>): number {
    return tasks.filter((task) => COUNTED_TASK_STATUSES.has(task.status)).length;
}

/** States whose status line never carries the ` · <K> tarefa(s)` segment (UI-03). */
const STATES_WITHOUT_TASKS: ReadonlySet<StatusLineState> = new Set(['sleeping', 'user_speaking']);

/**
 * Picks `<estado>` following the core frame precedence (UI-07) plus PLAN A3:
 * `Erro`; `DISCONNECTED` → `Voz indisponível`; operator/JARVIS speech;
 * `Aguardando aprovação`; `Pensando`/`Executando`; then the voice phase.
 */
function resolveState(input: StatusLineInput): StatusLineState {
    const { frame, voiceUnavailable } = resolveCoreFrame({
        assistantState: input.assistantState ?? 'IDLE',
        voicePhase: input.available ? input.phase : null,
    });

    switch (frame) {
        case 'ERROR':
            return 'error';
        case 'DISCONNECTED':
            return 'unavailable';
        case 'LISTENING_SPEECH':
            return 'user_speaking';
        case 'SPEAKING':
            return 'speaking';
    }

    if ((input.waitingApprovalCount ?? 0) >= 1) {
        return 'waitingApproval';
    }

    switch (frame) {
        case 'THINKING':
            return 'thinking';
        case 'EXECUTING':
            return 'executing';
        case 'LISTENING':
            return 'listening';
        default:
            return voiceUnavailable ? 'unavailable' : 'sleeping';
    }
}

/** Decides which parts of `<estado>[ · <N>s][ · <K> tarefa(s)]` are shown (UI-03, D8). */
export function statusLineParts(input: StatusLineInput): StatusLineParts {
    const state = resolveState(input);
    const seconds =
        state === 'listening' && input.countdownSeconds !== null && Number.isFinite(input.countdownSeconds)
            ? Math.max(0, Math.round(input.countdownSeconds))
            : null;
    const tasks =
        input.activeTaskCount >= 1 && !STATES_WITHOUT_TASKS.has(state) ? Math.trunc(input.activeTaskCount) : null;

    return { state, seconds, tasks };
}

/** Formats the header status line, e.g. `Ouvindo · 57s · 1 tarefa` (UI-03). */
export function formatStatusLine(input: StatusLineInput): string {
    const parts = statusLineParts(input);
    const t = i18n.global.t;
    const segments = [t(`header.statusLine.${parts.state}`)];

    if (parts.seconds !== null) {
        segments.push(t('header.statusLine.seconds', { n: parts.seconds }));
    }

    if (parts.tasks !== null) {
        segments.push(t('header.statusLine.tasks', parts.tasks));
    }

    return segments.join(STATUS_LINE_SEPARATOR);
}
