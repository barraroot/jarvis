<?php

namespace App\Exceptions;

use App\Models\Agent;
use RuntimeException;

/**
 * Thrown when an agent run exceeds its tool-call budget (US-7.3): the
 * execution is aborted and marked failed instead of running unsupervised.
 */
class ToolCallLimitExceededException extends RuntimeException
{
    public function __construct(Agent $agent, int $limit)
    {
        parent::__construct("O agente [{$agent->slug}] excedeu o limite de {$limit} chamadas de ferramenta por execução.");
    }
}
