import { i18n } from '@/i18n';
import type { VoicePhase } from '@/stores/voice';

/** Separator between the parts of the header status line (UI-02). */
export const STATUS_LINE_SEPARATOR = ' · ';

/** `<estado>` of the status line: the voice phase or the text-only warning (RF-08a). */
export type StatusLineState = VoicePhase | 'unavailable';

export interface StatusLineInput {
    phase: VoicePhase;
    /** False when the HUD runs text-only (RF-08a, D5). */
    available: boolean;
    /** Seconds left in the listening window; null while it is paused or closed (D8). */
    countdownSeconds: number | null;
    /** Tasks not yet in a terminal state. */
    activeTaskCount: number;
}

export interface StatusLineParts {
    state: StatusLineState;
    /** Only while the window is open and nobody is speaking. */
    seconds: number | null;
    /** Only with at least one non-terminal task. */
    tasks: number | null;
}

/** Decides which parts of `<estado>[ · <N>s][ · <K> tarefa(s)]` are shown (UI-02, D8). */
export function statusLineParts(input: StatusLineInput): StatusLineParts {
    const state: StatusLineState = input.available ? input.phase : 'unavailable';
    const seconds =
        state === 'listening' && input.countdownSeconds !== null && Number.isFinite(input.countdownSeconds)
            ? Math.max(0, Math.round(input.countdownSeconds))
            : null;
    const tasks = input.activeTaskCount >= 1 ? Math.trunc(input.activeTaskCount) : null;

    return { state, seconds, tasks };
}

/** Formats the header status line, e.g. `Ouvindo · 57s · 1 tarefa` (UI-02). */
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
