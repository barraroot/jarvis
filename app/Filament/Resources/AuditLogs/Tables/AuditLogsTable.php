<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Filament\Resources\AuditLogs\Schemas\AuditLogInfolist;
use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Audit trail filterable by actor, action, period and subject (US-12.3).
 * View is the only record action; there are no bulk actions.
 */
class AuditLogsTable
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
                TextColumn::make('action.slug')
                    ->label('Ação')
                    ->badge(),
                TextColumn::make('user.name')
                    ->label('Ator')
                    ->placeholder('Sistema')
                    ->searchable(),
                TextColumn::make('subject_type')
                    ->label('Alvo')
                    ->state(fn (AuditLog $record): ?string => AuditLogInfolist::subjectLabel($record))
                    ->placeholder('—'),
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('user')
                    ->label('Usuário')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('action')
                    ->label('Ação')
                    ->relationship('action', 'slug')
                    ->searchable()
                    ->preload(),
                Filter::make('period')
                    ->label('Período')
                    ->schema([
                        DatePicker::make('from')->label('De'),
                        DatePicker::make('until')->label('Até'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
                Filter::make('subject')
                    ->label('Alvo')
                    ->schema([
                        Select::make('subject_type')
                            ->label('Tipo do alvo')
                            ->options(fn (): array => AuditLog::query()
                                ->whereNotNull('subject_type')
                                ->distinct()
                                ->orderBy('subject_type')
                                ->pluck('subject_type')
                                ->mapWithKeys(fn (string $type): array => [$type => class_basename($type)])
                                ->all()),
                        TextInput::make('subject_id')
                            ->label('ID do alvo')
                            ->integer(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['subject_type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('subject_type', $type))
                        ->when($data['subject_id'] ?? null, fn (Builder $query, int|string $id): Builder => $query->where('subject_id', (int) $id))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
