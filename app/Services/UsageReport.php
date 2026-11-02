<?php

namespace App\Services;

use App\Models\AiModel;
use App\Models\UsageRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Aggregated consumption queries over `usage_records` for the admin dashboard
 * (US-5.3, US-12.1). Every query is a single SQL aggregate filtered by
 * `created_at`, covered by the `(user_id, created_at)` and
 * `(ai_model_id, created_at)` indexes.
 */
class UsageReport
{
    /**
     * Resolve a dashboard period from page filters (`from` / `until` dates),
     * defaulting to the current month up to today. Bounds are inclusive days.
     *
     * @param  array<string, mixed>|null  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function period(?array $filters): array
    {
        $from = filled($filters['from'] ?? null)
            ? CarbonImmutable::parse($filters['from'])->startOfDay()
            : CarbonImmutable::now()->startOfMonth();

        $until = filled($filters['until'] ?? null)
            ? CarbonImmutable::parse($filters['until'])->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        return [$from, $until];
    }

    /**
     * @return array{cost: float, input_tokens: int, output_tokens: int, tokens: int, calls: int, avg_latency_ms: float|null}
     */
    public function totals(CarbonImmutable $from, CarbonImmutable $until): array
    {
        /** @var object{cost: string|null, input_tokens: string|null, output_tokens: string|null, calls: string|int, avg_latency_ms: string|null} $row */
        $row = UsageRecord::query()
            ->whereBetween('created_at', [$from, $until])
            ->toBase()
            ->selectRaw('COALESCE(SUM(estimated_cost), 0) AS cost')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) AS input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) AS output_tokens')
            ->selectRaw('COUNT(*) AS calls')
            ->selectRaw('AVG(latency_ms) AS avg_latency_ms')
            ->first();

        $input = (int) $row->input_tokens;
        $output = (int) $row->output_tokens;

        return [
            'cost' => (float) $row->cost,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'tokens' => $input + $output,
            'calls' => (int) $row->calls,
            'avg_latency_ms' => $row->avg_latency_ms !== null ? round((float) $row->avg_latency_ms, 1) : null,
        ];
    }

    /**
     * @return array{cost: float, input_tokens: int, output_tokens: int, tokens: int, calls: int, avg_latency_ms: float|null}
     */
    public function today(): array
    {
        return $this->totals(CarbonImmutable::now()->startOfDay(), CarbonImmutable::now()->endOfDay());
    }

    /**
     * @return array{cost: float, input_tokens: int, output_tokens: int, tokens: int, calls: int, avg_latency_ms: float|null}
     */
    public function currentMonth(): array
    {
        return $this->totals(CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfDay());
    }

    /**
     * Users with consumption in the period, most expensive first, with
     * `period_cost`, `period_input_tokens`, `period_output_tokens`,
     * `period_calls` and `month_cost` (current month, for the monthly limit).
     *
     * @return Builder<User>
     */
    public function userRankingQuery(CarbonImmutable $from, CarbonImmutable $until, int $limit = 10): Builder
    {
        $inPeriod = fn (Builder $query): Builder => $query->whereBetween('created_at', [$from, $until]);

        return User::query()
            ->whereHas('usageRecords', $inPeriod)
            ->withSum(['usageRecords as period_cost' => $inPeriod], 'estimated_cost')
            ->withSum(['usageRecords as period_input_tokens' => $inPeriod], 'input_tokens')
            ->withSum(['usageRecords as period_output_tokens' => $inPeriod], 'output_tokens')
            ->withCount(['usageRecords as period_calls' => $inPeriod])
            ->withSum(['usageRecords as month_cost' => fn (Builder $query): Builder => $query
                ->where('created_at', '>=', Carbon::now()->startOfMonth())], 'estimated_cost')
            ->orderByDesc('period_cost')
            ->orderBy('users.id')
            ->limit($limit);
    }

    /**
     * Models used in the period with `period_cost`, token sums, `period_calls`
     * and `period_avg_latency_ms`, most expensive first.
     *
     * @return Builder<AiModel>
     */
    public function modelBreakdownQuery(CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        $inPeriod = fn (Builder $query): Builder => $query->whereBetween('created_at', [$from, $until]);

        return AiModel::query()
            ->withTrashed()
            ->whereHas('usageRecords', $inPeriod)
            ->withSum(['usageRecords as period_cost' => $inPeriod], 'estimated_cost')
            ->withSum(['usageRecords as period_input_tokens' => $inPeriod], 'input_tokens')
            ->withSum(['usageRecords as period_output_tokens' => $inPeriod], 'output_tokens')
            ->withCount(['usageRecords as period_calls' => $inPeriod])
            ->withAvg(['usageRecords as period_avg_latency_ms' => $inPeriod], 'latency_ms')
            ->orderByDesc('period_cost')
            ->orderBy('ai_models.id');
    }

    /**
     * Percentage of the monthly cost limit already consumed, or null when the user has no limit.
     */
    public static function limitPercent(User $user, float $monthCost): ?float
    {
        $limit = (float) ($user->monthly_cost_limit ?? 0);

        if ($user->monthly_cost_limit === null || $limit <= 0) {
            return null;
        }

        return round($monthCost / $limit * 100, 1);
    }
}
