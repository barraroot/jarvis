<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Audit report (US-12.3): exports the `audit_logs` of a date range as CSV —
 * to stdout or to `--output`. Properties are already secret-free (they pass
 * through `AuditLogger::sanitize()` when written).
 */
class AuditReport extends Command
{
    /**
     * @var list<string>
     */
    public const array COLUMNS = ['created_at', 'action', 'user_id', 'user_email', 'subject_type', 'subject_id', 'ip_address', 'properties'];

    protected $signature = 'jarvis:audit:report
        {--from= : First day included (YYYY-MM-DD); defaults to 30 days ago}
        {--to= : Last day included (YYYY-MM-DD); defaults to today}
        {--action=* : Only these action slugs}
        {--output= : Write the CSV to this path instead of stdout}';

    protected $description = 'Export the audit trail of a period as CSV';

    public function handle(): int
    {
        try {
            $from = CarbonImmutable::parse($this->option('from') ?? now()->subDays(30)->toDateString())->startOfDay();
            $to = CarbonImmutable::parse($this->option('to') ?? now()->toDateString())->endOfDay();
        } catch (Throwable) {
            $this->error('Invalid --from/--to date. Use YYYY-MM-DD.');

            return self::INVALID;
        }

        if ($from->greaterThan($to)) {
            $this->error('--from must be on or before --to.');

            return self::INVALID;
        }

        $path = $this->option('output');
        $handle = fopen(filled($path) ? $path : 'php://output', 'w');

        if ($handle === false) {
            $this->error("Cannot write to [{$path}].");

            return self::FAILURE;
        }

        fputcsv($handle, self::COLUMNS, escape: '');

        $rows = 0;

        AuditLog::query()
            ->with(['action', 'user'])
            ->whereBetween('created_at', [$from, $to])
            ->when($this->option('action') !== [], fn ($query) => $query->whereHas(
                'action',
                fn ($action) => $action->whereIn('slug', $this->option('action')),
            ))
            ->orderBy('created_at')
            ->orderBy('id')
            ->lazy()
            ->each(function (AuditLog $log) use ($handle, &$rows): void {
                fputcsv($handle, [
                    $log->created_at?->toIso8601String(),
                    $log->action?->slug,
                    $log->user_id,
                    $log->user?->email,
                    $log->subject_type,
                    $log->subject_id,
                    $log->ip_address,
                    $log->properties === null ? '' : json_encode($log->properties, JSON_UNESCAPED_UNICODE),
                ], escape: '');

                $rows++;
            });

        fclose($handle);

        if (filled($path)) {
            $this->info("{$rows} audit entries written to {$path}.");
        }

        return self::SUCCESS;
    }
}
