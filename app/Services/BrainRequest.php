<?php

namespace App\Services;

use App\Models\AgentExecution;
use App\Models\AiModel;
use App\Models\Message;
use App\Models\User;

/**
 * Input of a single AI text call handled by {@see BrainClient}.
 */
class BrainRequest
{
    /**
     * @param  list<array{role: string, content: string}>  $messages  Conversation history, oldest first.
     */
    public function __construct(
        public readonly User $user,
        public readonly AiModel $primaryModel,
        public readonly string $prompt,
        public readonly array $messages = [],
        public readonly ?string $instructions = null,
        public readonly ?AgentExecution $agentExecution = null,
        public readonly ?Message $message = null,
    ) {}
}
