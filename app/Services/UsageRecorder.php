<?php

namespace App\Services;

use App\Models\AgentExecution;
use App\Models\AiModel;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\VoiceSession;

/**
 * Persists a `usage_records` row for every AI call (US-5.3).
 *
 * Cost is estimated from the model's per-million token rates, never from the
 * provider's own billing data. Realtime voice usage (CT-07) is linked to its
 * voice session and to the provider `response_id` for idempotency.
 */
class UsageRecorder
{
    public function record(
        User $user,
        AiModel $model,
        int $inputTokens,
        int $outputTokens,
        int $latencyMs,
        string $statusSlug,
        ?AgentExecution $agentExecution = null,
        ?Message $message = null,
        ?VoiceSession $voiceSession = null,
        ?string $providerResponseId = null,
    ): UsageRecord {
        return UsageRecord::query()->create([
            'user_id' => $user->id,
            'ai_provider_id' => $model->ai_provider_id,
            'ai_model_id' => $model->id,
            'execution_status_id' => ExecutionStatus::idFor($statusSlug),
            'agent_execution_id' => $agentExecution?->id,
            'message_id' => $message?->id,
            'voice_session_id' => $voiceSession?->id,
            'provider_response_id' => $providerResponseId,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'estimated_cost' => $model->costFor($inputTokens, $outputTokens),
            'latency_ms' => $latencyMs,
            'created_at' => now(),
        ]);
    }
}
