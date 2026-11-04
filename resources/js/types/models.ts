export interface UserPreference {
    locale: string;
    speech_locale: string;
    voice_name: string | null;
    speech_rate: number;
    spoken_responses_enabled: boolean;
    wake_word_enabled: boolean;
    wake_word_consented_at: string | null;
    /** False when the consent predates the current consent text (RF-22); the wake word stays off. */
    wake_word_consent_valid: boolean;
    wake_word_consent_version?: number | null;
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
    notifications_unread?: number | null;
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
    /** Hypothesis memories proposed after this reply (US-6.2). */
    hypotheses?: MemoryHypothesis[] | null;
    /** Client-side id of an optimistic message not yet confirmed by the server. */
    localId?: string;
}

/** Minimal hypothesis payload rendered by the confirmation card in the chat. */
export interface MemoryHypothesis {
    id: string;
    content: string;
    status: string;
}

export type MemoryStatus = 'confirmed' | 'hypothesis';

/** Long-term memory (US-6.4). */
export interface Memory {
    id: string;
    content: string;
    status: MemoryStatus;
    category: string;
    source_conversation_id: string | null;
    source_message_id: string | null;
    confirmed_at: string | null;
    last_used_at: string | null;
    created_at: string;
    updated_at: string;
}

/** Agent run currently working, fed by `agent.*` events (Phase 12). */
export interface AgentActivity {
    execution_id: number;
    /** Agent slug (e.g. `personal-assistant`). */
    agent: string;
    depth: number;
    parent_id: number | null;
    status: 'running' | 'completed' | 'failed' | 'timed_out';
    progress_note: string | null;
    /** Client epoch ms of `agent.started`; duration is computed on finish. */
    started_at: number;
}

/** Finished agent run shown in the side panel history (Phase 12). */
export interface AgentHistoryEntry {
    execution_id: number;
    agent: string;
    depth: number;
    parent_id: number | null;
    status: 'completed' | 'failed' | 'timed_out';
    duration_ms: number;
}

/** Background task card shown in the side panel, fed by `task.updated` (US-10.2). */
export interface TaskSummary {
    id: string;
    title: string;
    status: TaskStatus;
    progress: number;
    agent?: { slug: string; name: string } | null;
    current_step?: { position: number; name: string; status: string } | null;
    result?: Record<string, unknown> | null;
    result_type?: string | null;
    error?: string | null;
    queued_at?: string | null;
    started_at?: string | null;
    completed_at?: string | null;
    created_at?: string;
    /** Model the task runs on (CT-04); null for older tasks. */
    model?: { name: string } | null;
    /** Visualization the task feeds (CT-04). */
    visualization_id?: number | null;
    /** Terminal voice tasks only: result wrapped as untrusted content for the voice announcement (RF-13). */
    voice_summary?: string | null;
}

/** One step of a task detail (US-10.2). */
export interface TaskStepSummary {
    id: number;
    position: number;
    name: string;
    status: TaskStatus;
    output: Record<string, unknown> | null;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
}

/** One leveled log line of a task detail. */
export interface TaskLogSummary {
    id: number;
    level: 'debug' | 'info' | 'warning' | 'error';
    message: string;
    context: Record<string, unknown> | null;
    task_step_id: number | null;
    created_at: string;
}

/** Full task detail returned by `GET /api/v1/tasks/{id}`. */
export interface TaskDetail extends TaskSummary {
    request: string;
    retry_of_task_id?: number | null;
    steps?: TaskStepSummary[];
    logs?: { data: TaskLogSummary[] };
    pending_approvals?: ApprovalSummary[];
}

/** Operator notification fed by `notification.created` (US-10.4). */
export interface NotificationSummary {
    id: string;
    type: string;
    title: string;
    body: string | null;
    data: Record<string, unknown> | null;
    read_at: string | null;
    created_at: string;
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
    /** Last enriched `tool.*` payload (CT-03), consumed by the AGENTES timeline. */
    event?: ToolEventPayload;
}

export type ApprovalStatus = 'pending' | 'approved' | 'denied' | 'expired';

/** Approval request as returned by `GET /api/v1/approvals` (US-8.3). */
export interface ApprovalSummary {
    id: string;
    status: ApprovalStatus;
    tool: {
        identifier: string;
        name: string;
        description: string;
    };
    effect: {
        is_write: boolean;
        is_sensitive: boolean;
        external: boolean;
    };
    params: Record<string, unknown>;
    agent: string | null;
    requested_at: string;
    expires_at: string;
    decided_at: string | null;
    decision_reason: string | null;
}

/** MCP connector status (US-9.4). */
export type ConnectorStatus = 'pending' | 'connected' | 'error' | 'disconnected';

/** `GET /api/v1/connectors` item (US-9.1/9.2): the credential is never sent, only `has_credential`. */
export interface ConnectorSummary {
    id: number;
    name: string;
    url: string;
    scope: 'global' | 'personal';
    is_active: boolean;
    status: ConnectorStatus;
    has_credential: boolean;
    tools_count: number;
    active_tools_count: number;
    last_synced_at: string | null;
    last_checked_at: string | null;
    last_error: string | null;
    can_manage: boolean;
    can_classify: boolean;
    created_at: string | null;
}

/** Tool discovered on a connector with its classification (US-9.3). `id` is the catalogue tool id. */
export interface ConnectorTool {
    id: number | null;
    definition_id: number;
    name: string;
    identifier: string | null;
    description: string | null;
    is_available: boolean;
    is_write: boolean;
    is_sensitive: boolean;
    is_active: boolean;
    last_seen_at: string | null;
}

/** `connector.status.changed` payload (US-9.4). */
export interface ConnectorStatusChange {
    id: number;
    name: string;
    scope: 'global' | 'personal';
    status: ConnectorStatus;
    previous_status: ConnectorStatus | null;
    last_error: string | null;
    last_checked_at: string | null;
    last_synced_at: string | null;
}

/** Kind of entry in the operator's activity history (US-12.4). */
export type ActivityType = 'agent' | 'tool' | 'approval';

/** One entry of `GET /api/v1/activity`: an agent run, a tool run or an approval decision. */
export interface ActivityItem {
    type: ActivityType;
    id: number;
    occurred_at: string | null;
    status: string;
    title: string;
    conversation_id: number | null;
    task_id: number | null;
    details: Record<string, unknown>;
}

/** Period/type filters of the activity history; empty strings mean "no filter". */
export interface ActivityFilters {
    from: string;
    to: string;
    type: ActivityType | '';
}

/** `POST /api/v1/voice/sessions` grant (CT-01): never carries the provider key. */
export interface VoiceSessionGrant {
    voice_session_id: number;
    conversation_id: number;
    /** Ephemeral Realtime credential, valid for ≤ 60 s if unused (RNF-02). */
    client_secret: string;
    expires_at: string;
    model: string;
    voice: string;
    listening_window_seconds: number;
}

/** `usage` of a Realtime `response.done`, reported through CT-07 (RF-10a). */
export interface RealtimeUsage {
    input_tokens: number;
    output_tokens: number;
    input_token_details?: Record<string, number>;
    output_token_details?: Record<string, number>;
}

/** CT-07 response: running total of the voice session against its ceiling. */
export interface VoiceUsageTotals {
    session_total_tokens: number;
    session_token_ceiling: number;
}

/** One transcribed voice turn batched to CT-10 (RF-21). */
export interface VoiceTurn {
    role: 'user' | 'assistant';
    content: string;
    spoken_at: string;
}

export type VisualizationStatus = 'syncing' | 'updated' | 'failed';

/** Result row of `02 RESULTADOS`; the `best` badge is rendered as `MELHOR RESULTADO`. */
export interface VisualizationResultItem {
    title: string;
    subtitle: string;
    badges: string[];
}

interface VisualizationSectionBase {
    key: string;
    position: number;
    label: string;
}

export interface VisualizationQuestionSection extends VisualizationSectionBase {
    type: 'question';
    headline: string;
}

export interface VisualizationResultsSection extends VisualizationSectionBase {
    type: 'results';
    status: 'syncing' | 'updated';
    items: VisualizationResultItem[];
}

export interface VisualizationTextSection extends VisualizationSectionBase {
    type: 'text';
    text: string;
}

/** Section of a visualization, discriminated by `type` (CT-05). */
export type VisualizationSection = VisualizationQuestionSection | VisualizationResultsSection | VisualizationTextSection;

/** Progressive visualization (CT-05, CT-08, CT-09); every text is already neutralized server-side (RF-20). */
export interface Visualization {
    id: number;
    task_id: number | null;
    title: string;
    source: string | null;
    status: VisualizationStatus;
    created_at: string;
    updated_at: string;
    closed_at: string | null;
    sections: VisualizationSection[];
}

/** Enriched `tool.started` / `tool.completed` / `tool.failed` payload (CT-03). */
export interface ToolEventPayload {
    tool: string;
    execution_id: number;
    status: string;
    task_id: number | null;
    agent_execution_id: number | null;
    /** `<connector> · <tool>` for MCP tools, the tool name otherwise. */
    display_name: string;
    input_summary: string;
    /** Only on `tool.completed`. */
    output_bytes?: number | null;
    started_at: string;
    finished_at: string | null;
}
