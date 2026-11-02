<?php

namespace App\Listeners;

use App\Events\Approval\ApprovalDecided;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;

/**
 * On approval, runs the tool exactly once through the Tool Engine, so the
 * resulting `tool_execution` carries `approval_id` (workflow 4, step 4).
 */
class ExecuteApprovedTool
{
    public function handle(ApprovalDecided $event): void
    {
        if (! $event->approved) {
            return;
        }

        $approval = $event->approval->fresh();

        if ($approval === null) {
            return;
        }

        app(ToolExecutor::class)->execute(
            $approval->tool,
            $approval->input,
            ToolContext::forApproval($approval),
        );
    }
}
