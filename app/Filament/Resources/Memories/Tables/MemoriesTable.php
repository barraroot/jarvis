<?php

namespace App\Filament\Resources\Memories\Tables;

use App\Models\Memory;
use App\Services\MemoryService;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Every memory from every operator, filterable by user, status and category.
 * Deletion is the only action: hard delete with the `memory_deleted` audit
 * entry and embedding removal (US-12.1, US-12.3). Content is never edited.
 */
class MemoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('content')
                    ->label('Conteúdo')
                    ->searchable()
                    ->wrap()
                    ->limit(300),
                TextColumn::make('user.name')
                    ->label('Usuário')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status.slug')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'confirmed' ? 'success' : 'warning'),
                TextColumn::make('category.slug')
                    ->label('Categoria')
                    ->badge(),
                TextColumn::make('source_conversation_id')
                    ->label('Conversa')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? '#'.$state : '—'),
                TextColumn::make('confirmed_at')
                    ->label('Confirmada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('last_used_at')
                    ->label('Último uso')
                    ->since()
                    ->placeholder('Nunca')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
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
                SelectFilter::make('category')
                    ->label('Categoria')
                    ->relationship('category', 'name'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->authorize(fn (): true => true)
                    ->action(function (Memory $record): void {
                        app(MemoryService::class)->delete($record, auth()->user());
                    }),
            ]);
    }
}
