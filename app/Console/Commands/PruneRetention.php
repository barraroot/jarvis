<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\TaskLog;
use App\Models\ToolExecution;
use App\Models\UsageRecord;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retention pruning (Notes & Conventions, US-12.2): daily removal of expired
 * execution data — `tool_executions` and `task_logs` after their day-based
 * thresholds, `usage_records` after its month threshold and read
 * notifications after theirs. Conversations, audit logs and memories are
 * never touched. Counts are logged for observability; `--dry-run` only
 * prints them. No audit entries are generated (housekeeping, not an action).
 */
class PruneRetention extends Command
{
    protected $signature = 'jarvis:retention:prune {--dry-run : Print the counts without deleting anything}';

    protected $description = 'Delete execution logs, usage records and read notifications past their retention thresholds';

    public function handle(Settings $settings): int
    {
        $counts = [
            'tool_executions' => $this->countExpiredToolExecutions($settings),
            'task_logs' => $this->countExpiredTaskLogs($settings),
            'usage_records' => $this->countExpiredUsageRecords($settings),
            'notifications' => $this->countExpiredReadNotifications($settings),
        ];

        foreach ($counts as $table => $count) {
            $this->line("{$table}: {$count}");
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing deleted.');

            return self::SUCCESS;
        }

        $this->deleteExpiredToolExecutions($settings);
        $this->deleteExpiredTaskLogs($settings);
        $this->deleteExpiredUsageRecords($settings);
        $this->deleteExpiredReadNotifications($settings);

        Log::info('jarvis:retention:prune removed records.', $counts);

        return self::SUCCESS;
    }

    private function countExpiredToolExecutions(Settings $settings): int
    {
        return ToolExecution::query()
            ->where('created_at', '<', now()->subDays($settings->toolExecutionsRetentionDays()))
            ->count();
    }

    private function countExpiredTaskLogs(Settings $settings): int
    {
        return TaskLog::query()
            ->where('created_at', '<', now()->subDays($settings->taskLogsRetentionDays()))
            ->count();
    }

    private function countExpiredUsageRecords(Settings $settings): int
    {
        return UsageRecord::query()
            ->where('created_at', '<', now()->subMonths($settings->usageRecordsRetentionMonths()))
            ->count();
    }

    private function countExpiredReadNotifications(Settings $settings): int
    {
        return Notification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays($settings->readNotificationsRetentionDays()))
            ->count();
    }

    private function deleteExpiredToolExecutions(Settings $settings): void
    {
        ToolExecution::query()
            ->where('created_at', '<', now()->subDays($settings->toolExecutionsRetentionDays()))
            ->delete();
    }

    private function deleteExpiredTaskLogs(Settings $settings): void
    {
        TaskLog::query()
            ->where('created_at', '<', now()->subDays($settings->taskLogsRetentionDays()))
            ->delete();
    }

    private function deleteExpiredUsageRecords(Settings $settings): void
    {
        UsageRecord::query()
            ->where('created_at', '<', now()->subMonths($settings->usageRecordsRetentionMonths()))
            ->delete();
    }

    private function deleteExpiredReadNotifications(Settings $settings): void
    {
        Notification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays($settings->readNotificationsRetentionDays()))
            ->delete();
    }
}
