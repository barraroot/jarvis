<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Services\UsageReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * Top 10 users by cost in the filtered period, with the monthly limit and the
 * share of it already consumed this month (US-5.3).
 */
class UsageByUserTable extends TableWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Consumo por usuário (top 10)')
            ->query(fn () => app(UsageReport::class)->userRankingQuery(...UsageReport::period($this->pageFilters)))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Usuário'),
                TextColumn::make('period_cost')
                    ->label('Custo')
                    ->money('BRL'),
                TextColumn::make('period_tokens')
                    ->label('Tokens')
                    ->state(fn (User $record): int => (int) $record->period_input_tokens + (int) $record->period_output_tokens)
                    ->numeric(),
                TextColumn::make('period_calls')
                    ->label('Chamadas')
                    ->numeric(),
                TextColumn::make('monthly_cost_limit')
                    ->label('Limite mensal')
                    ->money('BRL')
                    ->placeholder('Sem limite'),
                TextColumn::make('limit_percent')
                    ->label('% do limite (mês)')
                    ->state(fn (User $record): ?float => UsageReport::limitPercent($record, (float) $record->month_cost))
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : number_format($state, 1, ',', '.').'%')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 100 => 'danger',
                        $state >= 80 => 'warning',
                        default => 'success',
                    }),
            ]);
    }
}
