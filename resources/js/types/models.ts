export interface UserPreference {
    locale: string;
    speech_locale: string;
    voice_name: string | null;
    speech_rate: number;
    spoken_responses_enabled: boolean;
    wake_word_enabled: boolean;
    wake_word_consented_at: string | null;
    silence_timeout_ms: number;
    extra: Record<string, unknown> | null;
}

export interface User {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_active: boolean;
    last_login_at: string | null;
    preference?: UserPreference;
}

export interface LoginCredentials {
    email: string;
    password: string;
    remember?: boolean;
}

export type AssistantState = 'IDLE' | 'LISTENING' | 'THINKING' | 'SPEAKING' | 'EXECUTING' | 'SLEEPING' | 'ERROR' | 'DISCONNECTED';

export type TaskStatus = 'pending' | 'queued' | 'running' | 'waiting_approval' | 'completed' | 'failed' | 'cancelled';

export type MessageRole = 'user' | 'assistant' | 'tool' | 'system';

export type MessageSource = 'text' | 'voice';

export type MessageStatus = 'pending' | 'running' | 'completed' | 'failed';

/** Text model offered by the AI brain (`GET /api/v1/ai/models`). */
export interface AiModelSummary {
    id: string;
    name: string;
    provider: string;
    is_default: boolean;
}

export interface Conversation {
    id: string;
    title: string;
    last_activity_at: string;
    ai_model_id?: string | null;
    model?: AiModelSummary | null;
}

export interface Message {
    id: string;
    conversation_id: string;
    role: MessageRole;
    source: MessageSource | null;
    status: MessageStatus;
    content: string;
    payload: Record<string, unknown> | null;
    ai_model_id: string | null;
    created_at: string;
    /** Client-side id of an optimistic message not yet confirmed by the server. */
    localId?: string;
}

/** Agent currently working, fed by `agent.*` events (Phase 11). */
export interface ActiveAgent {
    name: string;
    status: AssistantState | TaskStatus;
    running: boolean;
}

/** Background task card shown in the side panel, fed by task events (Phase 12). */
export interface TaskCard {
    id: string;
    title: string;
    progress: number;
    status: TaskStatus;
}

/** Terminal-style execution history line (agents, tools), fed by events (Phase 13). */
export interface ActivityEntry {
    id: string;
    kind: string;
    text: string;
    timestamp: string;
}

/** Tool status row for the side panel, fed by `tool.*` events (Phase 11). */
export interface ToolState {
    id: string;
    name: string;
    status: 'active' | 'error' | 'idle';
}
