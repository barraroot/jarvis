<?php

namespace App\Services;

use App\Models\Memory;
use App\Models\User;

/**
 * Injects the user's relevant confirmed memories into the AI context (US-6.3)
 * as a single "O que você sabe sobre o usuário (fatos confirmados)" block.
 * Hypotheses are never injected (US-6.2).
 */
class MemoryContextSource implements ContextSource
{
    public function __construct(
        private readonly User $user,
        private readonly string $query,
        private readonly MemoryRetriever $retriever,
    ) {}

    /**
     * @return list<array{role: string, content: string}>
     */
    public function contextMessages(): array
    {
        $memories = $this->retriever->relevantFor($this->user, $this->query);

        if ($memories->isEmpty()) {
            return [];
        }

        $facts = $memories
            ->map(fn (Memory $memory): string => "- {$memory->content}")
            ->implode("\n");

        return [[
            'role' => 'system',
            'content' => "O que você sabe sobre o usuário (fatos confirmados):\n{$facts}",
        ]];
    }
}
