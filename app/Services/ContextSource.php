<?php

namespace App\Services;

/**
 * A source of extra context messages for an AI call. Memories (Phase 10) and
 * agent instructions (Phase 12) will implement this interface; the
 * {@see ContextBuilder} reserves a slot for them in the token budget.
 */
interface ContextSource
{
    /**
     * @return list<array{role: string, content: string}>
     */
    public function contextMessages(): array;
}
