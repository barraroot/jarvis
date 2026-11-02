<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AiModel;

/**
 * Result of routing a user message: the model that will produce the reply,
 * the optional instructions for the AI call and the optional agent that runs
 * it (Phase 12). The prompt may override the user message content (the `@slug`
 * prefix is stripped before it reaches the model).
 */
class RoutedMessage
{
    public function __construct(
        public readonly AiModel $model,
        public readonly ?string $instructions = null,
        public readonly ?Agent $agent = null,
        public readonly ?string $prompt = null,
    ) {}
}
