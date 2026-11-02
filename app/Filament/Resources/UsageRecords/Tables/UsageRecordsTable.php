<?php

namespace App\Filament\Resources\UsageRecords\Tables;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Usage records filterable by user, model, status and period (US-5.3).
 */
class UsageRecordsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Usuário')
                    ->searchable(),
                TextColumn::make('provider.name')
                    ->label('Provedor'),
                TextColumn::make('model.name')
                    ->label('Modelo'),
                TextColumn::make('status.slug')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'completed' ? 'success' : 'danger'),
                TextColumn::make('input_tokens')
                    ->label('Tokens entrada')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('output_tokens')
                    ->label('Tokens saída')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('estimated_cost')
                    ->label('Custo estimado')
                    ->money('BRL', decimalPlaces: 6)
                    ->sortable(),
                TextColumn::make('latency_ms')
                    ->label('Latência (ms)')
                    ->numeric()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('user')
                    ->label('Usuário')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('model')
                    ->label('Modelo')
                    ->relationship('model', 'name', fn (Builder $query): Builder => $query->withTrashed())
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->relationship('status', 'name'),
                Filter::make('period')
                    ->label('Período')
                    ->schema([
                        DatePicker::make('from')->label('De'),
                        DatePicker::make('until')->label('Até'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
