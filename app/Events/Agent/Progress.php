<?php

namespace App\Events\Agent;

/**
 * `agent.progress` on the operator's private channel (US-7.4): an agent step,
 * such as a tool call, with a human-readable note.
 */
class Progress extends AgentEvent
{
    public function broadcastAs(): string
    {
        return 'agent.progress';
    }
}
