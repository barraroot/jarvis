<?php

namespace App\Tools;

use App\Models\AgentExecution;
use App\Models\Approval;
use App\Models\Task;
use App\Models\TaskStep;
use App\Models\User;

/**
 * Everything the Tool Engine knows about the execution site: the operator who
 * triggered it, the task/agent run it belongs to, and the approval gating it
 * (when one was required).
 */
final class ToolContext
{
    public function __construct(
        public readonly User $user,
        public readonly ?Task $task = null,
        public readonly ?AgentExecution $agentExecution = null,
        public readonly ?TaskStep $taskStep = null,
        public readonly ?Approval $approval = null,
    ) {}

    /**
     * Context for running a tool after its approval was granted.
     */
    public static function forApproval(Approval $approval): self
    {
        return new self(
            user: $approval->user,
            task: $approval->task,
            agentExecution: $approval->agentExecution,
            approval: $approval,
        );
    }

    public function withApproval(?Approval $approval): self
    {
        return new self($this->user, $this->task, $this->agentExecution, $this->taskStep, $approval);
    }
}
