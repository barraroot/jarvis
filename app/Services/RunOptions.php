<?php

namespace App\Services;

use App\Models\AgentExecution;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;

/**
 * Everything an agent run needs to know about its execution site: the
 * conversation/message it answers in (when it streams to the chat), the task
 * it belongs to, the delegation chain position and the extra context sources
 * (memories) injected into the model.
 */
final class RunOptions
{
    /**
     * @param  list<ContextSource>  $contextSources
     */
    public function __construct(
        public readonly ?Conversation $conversation = null,
        public readonly ?Message $message = null,
        public readonly ?Task $task = null,
        public readonly ?AgentExecution $parent = null,
        public readonly ?int $depth = null,
        public readonly array $contextSources = [],
    ) {}

    /**
     * Explicit depth wins; otherwise the run is one level below its parent
     * (delegation), or at the root when there is no parent.
     */
    public function resolvedDepth(): int
    {
        if ($this->depth !== null) {
            return $this->depth;
        }

        return $this->parent !== null ? $this->parent->depth + 1 : 0;
    }
}
