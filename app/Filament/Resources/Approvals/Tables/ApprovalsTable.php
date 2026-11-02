<?php

namespace App\Filament\Resources\Approvals\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Approvals of every operator, filterable by user, status and tool.
 * View is the only action: no approve/deny for the admin (US-8.3).
 */
class ApprovalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('requested_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Usuário')
                    ->searchable(),
                TextColumn::make('tool.name')
                    ->label('Ferramenta'),
                TextColumn::make('status.slug')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'denied' => 'danger',
                        'expired' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('requested_at')
                    ->label('Solicitada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('decided_at')
                    ->label('Decidida em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
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
                SelectFilter::make('tool')
                    ->label('Ferramenta')
                    ->relationship('tool', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
