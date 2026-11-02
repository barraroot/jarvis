<?php

namespace App\Services;

use App\Models\AiModel;

/**
 * Completed AI text call returned by {@see BrainClient::stream()}.
 */
class BrainResponse
{
    public function __construct(
        public readonly string $text,
        public readonly AiModel $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $latencyMs,
    ) {}
}
