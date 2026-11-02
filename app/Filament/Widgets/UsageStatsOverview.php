<?php

namespace App\Filament\Widgets;

use App\Services\UsageReport;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Cost, tokens and latency of today, this month and the filtered period (US-5.3).
 */
class UsageStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $report = app(UsageReport::class);

        $today = $report->today();
        $month = $report->currentMonth();
        $period = $report->totals(...UsageReport::period($this->pageFilters));

        return [
            Stat::make('Custo hoje', self::money($today['cost']))
                ->description(self::tokens($today['tokens']).' tokens'),
            Stat::make('Custo no mês', self::money($month['cost']))
                ->description(self::tokens($month['tokens']).' tokens'),
            Stat::make('Custo no período', self::money($period['cost']))
                ->description(self::tokens($period['tokens']).' tokens · '.$period['calls'].' chamadas'),
            Stat::make('Latência média', $period['avg_latency_ms'] !== null ? number_format($period['avg_latency_ms'], 0, ',', '.').' ms' : '—')
                ->description('no período filtrado'),
        ];
    }

    public static function money(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    public static function tokens(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
