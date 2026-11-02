<?php

namespace App\Filament\Resources\Conversations\Tables;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every conversation from every operator, filterable by user and period.
 * No record actions: the resource is read-only (US-12.1).
 */
class ConversationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_activity_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Usuário')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('messages_count')
                    ->label('Mensagens')
                    ->counts('messages')
                    ->sortable(),
                TextColumn::make('model.name')
                    ->label('Modelo')
                    ->placeholder('—'),
                TextColumn::make('last_activity_at')
                    ->label('Última atividade')
                    ->since()
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
                Filter::make('period')
                    ->label('Período')
                    ->form([
                        DatePicker::make('from')
                            ->label('De'),
                        DatePicker::make('until')
                            ->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('conversations.created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('conversations.created_at', '<=', $date));
                    }),
            ]);
    }
}
