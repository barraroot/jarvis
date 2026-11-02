<?php

namespace App\Events\Agent;

/**
 * `agent.failed` on the operator's private channel (US-7.4): limits exceeded,
 * provider failures or timeouts. The execution status distinguishes
 * `failed` from `timed_out`.
 */
class Failed extends AgentEvent
{
    public function broadcastAs(): string
    {
        return 'agent.failed';
    }
}
