<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AiModel;

/**
 * Result of routing a user message: the model that will produce the reply and
 * the optional instructions for the AI call.
 */
class RoutedMessage
{
    public function __construct(
        public readonly AiModel $model,
        public readonly ?string $instructions = null,
        public readonly ?Agent $agent = null,
    ) {}
}
