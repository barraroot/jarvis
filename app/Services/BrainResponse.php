<?php

namespace App\Services;

use App\Models\AiModel;

/**
 * Completed AI text call returned by {@see BrainClient::stream()}.
 */
class BrainResponse
{
    /**
     * @param  array{type: string, payload: array<string, mixed>}|null  $structuredResult  Normalized structured result (Phase 14), null when the reply stayed plain text.
     */
    public function __construct(
        public readonly string $text,
        public readonly AiModel $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $latencyMs,
        public readonly ?array $structuredResult = null,
    ) {}
}
