<?php

namespace App\Events\Agent;

/**
 * `agent.started` on the operator's private channel (US-7.4).
 */
class Started extends AgentEvent
{
    public function broadcastAs(): string
    {
        return 'agent.started';
    }
}
