<?php

namespace App\Console\Commands;

use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Services\ApprovalService;
use Illuminate\Console\Command;

/**
 * Closes approval windows that ran out (US-8.3): pending rows past
 * `expires_at` become `expired` (treated as denials) and are audited.
 * Scheduled every minute.
 */
class ExpireApprovals extends Command
{
    protected $signature = 'jarvis:approvals:expire';

    protected $description = 'Expire pending approvals past their window';

    public function handle(ApprovalService $approvals): int
    {
        $expired = Approval::query()
            ->with(['tool', 'user', 'task', 'status'])
            ->where('approval_status_id', ApprovalStatus::idFor(ApprovalStatus::PENDING))
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $approval) {
            $approvals->expire($approval);
        }

        $this->info("Expired {$expired->count()} approvals.");

        return self::SUCCESS;
    }
}
