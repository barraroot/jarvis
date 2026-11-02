<?php

namespace App\Services;

use App\Models\AgentExecution;
use App\Models\AiModel;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\UsageRecord;
use App\Models\User;

/**
 * Persists a `usage_records` row for every AI call (US-5.3).
 *
 * Cost is estimated from the model's per-million token rates, never from the
 * provider's own billing data.
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
    ): UsageRecord {
        return UsageRecord::query()->create([
            'user_id' => $user->id,
            'ai_provider_id' => $model->ai_provider_id,
            'ai_model_id' => $model->id,
            'execution_status_id' => ExecutionStatus::idFor($statusSlug),
            'agent_execution_id' => $agentExecution?->id,
            'message_id' => $message?->id,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'estimated_cost' => $model->costFor($inputTokens, $outputTokens),
            'latency_ms' => $latencyMs,
            'created_at' => now(),
        ]);
    }
}
