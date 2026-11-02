<?php

namespace App\Services;

use App\Exceptions\NoModelAvailableException;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves which AI model a conversation should use (US-5.2).
 *
 * Precedence: the conversation's own model, the agent's model, then the global
 * default text model. Only active models of active providers are considered.
 */
class ModelSelector
{
    public function forConversation(Conversation $conversation, ?Agent $agent = null): AiModel
    {
        $candidates = array_values(array_filter([
            $this->usableTextModel($conversation->ai_model_id),
            $this->usableTextModel($agent?->ai_model_id),
        ]));

        if ($candidates !== []) {
            return $candidates[0];
        }

        return $this->defaultTextModel()
            ?? throw new NoModelAvailableException('No active text AI model is available. Register and activate a default text model first.');
    }

    public function embeddingModel(): AiModel
    {
        return AiModel::query()
            ->active()
            ->embeddings()
            ->where('is_default_embedding', true)
            ->whereHas('provider', fn (Builder $query) => $query->active())
            ->first()
            ?? throw new NoModelAvailableException('No active embedding AI model is available. Register and activate a default embedding model first.');
    }

    private function usableTextModel(?int $modelId): ?AiModel
    {
        if ($modelId === null) {
            return null;
        }

        return AiModel::query()
            ->with('provider')
            ->whereKey($modelId)
            ->active()
            ->text()
            ->whereHas('provider', fn (Builder $query) => $query->active())
            ->first();
    }

    private function defaultTextModel(): ?AiModel
    {
        return AiModel::query()
            ->with('provider')
            ->active()
            ->text()
            ->where('is_default_text', true)
            ->whereHas('provider', fn (Builder $query) => $query->active())
            ->first();
    }
}
