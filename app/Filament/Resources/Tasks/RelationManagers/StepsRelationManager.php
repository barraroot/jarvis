<?php

namespace App\Filament\Resources\Tasks\RelationManagers;

use App\Models\TaskStep;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Steps of a task on its admin view page. Read-only.
 */
class StepsRelationManager extends RelationManager
{
    protected static string $relationship = 'steps';

    protected static ?string $title = 'Etapas';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('position')
            ->columns([
                TextColumn::make('position')
                    ->label('#'),
                TextColumn::make('name')
                    ->label('Etapa'),
                TextColumn::make('status.slug')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('output')
                    ->label('Saída')
                    ->state(fn (TaskStep $record): ?string => $record->output === null ? null : json_encode($record->output, JSON_UNESCAPED_UNICODE))
                    ->limit(80)
                    ->placeholder('—'),
                TextColumn::make('error')
                    ->label('Erro')
                    ->limit(80)
                    ->placeholder('—'),
                TextColumn::make('started_at')
                    ->label('Início')
                    ->dateTime('d/m/Y H:i:s')
                    ->placeholder('—'),
                TextColumn::make('finished_at')
                    ->label('Fim')
                    ->dateTime('d/m/Y H:i:s')
                    ->placeholder('—'),
            ]);
    }
}
