<?php

namespace App\Exceptions;

use App\Models\Agent;
use RuntimeException;

/**
 * Thrown when an execution is requested for a deactivated agent (US-7.2):
 * inactive agents are never selected by the brain nor accept delegation.
 */
class AgentUnavailableException extends RuntimeException
{
    public function __construct(Agent $agent)
    {
        parent::__construct("O agente [{$agent->slug}] está inativo e não pode ser executado.");
    }
}
