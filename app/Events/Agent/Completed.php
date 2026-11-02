<?php

namespace App\Events\Agent;

/**
 * `agent.completed` on the operator's private channel (US-7.4).
 */
class Completed extends AgentEvent
{
    public function broadcastAs(): string
    {
        return 'agent.completed';
    }
}
