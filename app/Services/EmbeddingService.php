<?php

namespace App\Services;

use App\Exceptions\EmbeddingDimensionMismatchException;
use App\Models\ExecutionStatus;
use App\Models\MemoryEmbedding;
use App\Models\User;
use Laravel\Ai\Embeddings;

/**
 * Generates 1536-dimension embeddings with the default embedding model and
 * records usage for every call (Phase 10 / US-5.3).
 */
class EmbeddingService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ModelSelector $modelSelector,
        private readonly UsageRecorder $usageRecorder,
    ) {}

    /**
     * Embed a single string with the default embedding model.
     *
     * @return array<int, float>
     *
     * @throws EmbeddingDimensionMismatchException
     */
    public function embed(string $text, ?User $user = null): array
    {
        $model = $this->modelSelector->embeddingModel();

        $this->registry->register();

        $startedAt = microtime(true);

        $response = Embeddings::for([$text])
            ->dimensions(MemoryEmbedding::DIMENSIONS)
            ->generate($model->provider->slug, $model->model_identifier);

        $embedding = $response->first();

        if (count($embedding) !== MemoryEmbedding::DIMENSIONS) {
            throw new EmbeddingDimensionMismatchException(count($embedding), MemoryEmbedding::DIMENSIONS);
        }

        if ($user !== null) {
            $this->usageRecorder->record(
                $user,
                $model,
                $response->usage->inputTokens,
                $response->usage->outputTokens,
                $this->elapsedMs($startedAt),
                ExecutionStatus::COMPLETED,
            );
        }

        return $embedding;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
