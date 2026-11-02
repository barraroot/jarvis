<?php

namespace App\Events\Approval;

use App\Models\Approval;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after the owner approves or denies an approval (US-8.3); expiry by the
 * scheduler is treated as a denial and fires it with a null decider. Internal:
 * listeners resume the task, execute the tool on approval and tell the agent
 * about a refusal.
 */
class ApprovalDecided
{
    use Dispatchable;

    public function __construct(
        public readonly Approval $approval,
        public readonly ?User $decider,
        public readonly bool $approved,
        public readonly ?string $reason = null,
    ) {}
}
