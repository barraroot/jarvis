import type { VoicePhase } from '@/stores/voice';
import type { AssistantState } from '@/types/models';

/** The 8 visual frames of the JARVIS core (UI-07, `board-04-jarvis-core.html`). */
export type CoreFrame =
    | 'SLEEPING'
    | 'LISTENING'
    | 'LISTENING_SPEECH'
    | 'SPEAKING'
    | 'THINKING'
    | 'EXECUTING'
    | 'ERROR'
    | 'DISCONNECTED';

export interface CoreFrameInput {
    assistantState: AssistantState;
    /** Local voice phase; null while voice is unavailable (text-only HUD). */
    voicePhase: VoicePhase | null;
}

export interface ResolvedCoreFrame {
    frame: CoreFrame;
    /** True when the `SLEEPING` frame stands for "voice unavailable" (no ninth frame, UI-07). */
    voiceUnavailable: boolean;
}

/**
 * Resolves the core frame from the server assistant state and the local voice
 * phase, in the precedence of UI-07: (1) assistant `ERROR`/`DISCONNECTED`;
 * (2) voice `user_speaking`/`speaking`; (3) assistant `THINKING`/`EXECUTING`
 * while the voice phase is `sleeping`, `listening` or absent; (4) the voice
 * phase. Without a voice phase the core sleeps labelled "Voz indisponível".
 */
export function resolveCoreFrame(input: CoreFrameInput): ResolvedCoreFrame {
    const { assistantState, voicePhase } = input;

    if (assistantState === 'ERROR' || assistantState === 'DISCONNECTED') {
        return { frame: assistantState, voiceUnavailable: false };
    }

    if (voicePhase === 'user_speaking') {
        return { frame: 'LISTENING_SPEECH', voiceUnavailable: false };
    }

    if (voicePhase === 'speaking') {
        return { frame: 'SPEAKING', voiceUnavailable: false };
    }

    if (assistantState === 'THINKING' || assistantState === 'EXECUTING') {
        return { frame: assistantState, voiceUnavailable: false };
    }

    if (voicePhase === 'listening') {
        return { frame: 'LISTENING', voiceUnavailable: false };
    }

    return { frame: 'SLEEPING', voiceUnavailable: voicePhase === null };
}
