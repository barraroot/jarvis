<?php

namespace App\Ai\Agents;

use Laravel\Ai\AnonymousAgent;

/**
 * Runtime agent built by `AgentRuntimeFactory` for a catalogue agent (US-7.3).
 * Without an explicit step budget the SDK caps the loop at ~1.5 steps per
 * tool, so the agent's own tool-call limit could never be reached; the budget
 * here allows exactly one step beyond the limit, letting `AgentRunner` see
 * the offending call and block it.
 */
class RuntimeAgent extends AnonymousAgent
{
    /**
     * @param  iterable<mixed>  $messages
     * @param  iterable<object>  $tools
     */
    public function __construct(
        string $instructions,
        iterable $messages,
        iterable $tools,
        public int $toolCallLimit = 20,
    ) {
        parent::__construct($instructions, $messages, $tools);
    }

    /**
     * Steps of the generation loop: one per allowed tool call, the final
     * answer, and one extra so a call beyond the limit surfaces.
     */
    public function maxSteps(): int
    {
        return max(1, $this->toolCallLimit) + 2;
    }
}
