<?php

namespace App\Filament\Resources\Conversations\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Messages of a conversation on its admin view page. Read-only.
 */
class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('id')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('role.name')
                    ->label('Papel')
                    ->badge(),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('content')
                    ->label('Conteúdo')
                    ->limit(80)
                    ->tooltip(fn ($record): ?string => $record->content),
                TextColumn::make('created_at')
                    ->label('Enviada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ]);
    }
}
