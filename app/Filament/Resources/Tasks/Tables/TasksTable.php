<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Filament\Resources\Tasks\TaskResource;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Tasks of every operator, filterable by user and status. View and the
 * audited admin cancel are the only actions.
 */
class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->limit(60),
                TextColumn::make('user.name')
                    ->label('Usuário')
                    ->searchable(),
                TextColumn::make('status.slug')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'cancelled' => 'gray',
                        'waiting_approval' => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('progress')
                    ->label('Progresso')
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('agent.name')
                    ->label('Agente')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('user')
                    ->label('Usuário')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->relationship('status', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                TaskResource::cancelAction(),
            ])
            ->toolbarActions([]);
    }
}
