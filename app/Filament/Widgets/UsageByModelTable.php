<?php

namespace App\Filament\Widgets;

use App\Models\AiModel;
use App\Services\UsageReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * Cost, tokens, calls and average latency per model in the filtered period (US-5.3).
 */
class UsageByModelTable extends TableWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Consumo por modelo')
            ->query(fn () => app(UsageReport::class)->modelBreakdownQuery(...UsageReport::period($this->pageFilters)))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Modelo'),
                TextColumn::make('provider.name')
                    ->label('Provedor'),
                TextColumn::make('period_cost')
                    ->label('Custo')
                    ->money('BRL'),
                TextColumn::make('period_tokens')
                    ->label('Tokens')
                    ->state(fn (AiModel $record): int => (int) $record->period_input_tokens + (int) $record->period_output_tokens)
                    ->numeric(),
                TextColumn::make('period_calls')
                    ->label('Chamadas')
                    ->numeric(),
                TextColumn::make('period_avg_latency_ms')
                    ->label('Latência média (ms)')
                    ->numeric(decimalPlaces: 0)
                    ->placeholder('—'),
            ]);
    }
}
