<?php

namespace App\Filament\Resources\Agents\Tables;

use App\Filament\Actions\ToggleActiveBulkActions;
use App\Models\Agent;
use App\Models\AuditAction;
use App\Services\DelegationService;
use App\Support\AuditLogger;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class AgentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('model.name')
                    ->label('Modelo')
                    ->placeholder('Padrão global')
                    ->sortable(),
                TextColumn::make('tools_count')
                    ->label('Ferramentas')
                    ->counts('tools')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('max_delegation_depth')
                    ->label('Profundidade')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('max_tool_calls')
                    ->label('Máx. chamadas')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('timeout_seconds')
                    ->label('Timeout (s)')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_default')
                    ->label('Padrão')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Ativo'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->action(function (Agent $record): void {
                        $record->delete();

                        app(AuditLogger::class)->log(AuditAction::AGENT_DELETED, auth()->user(), $record, [
                            'slug' => $record->slug,
                            'name' => $record->name,
                        ]);
                    }),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ...ToggleActiveBulkActions::make(AuditAction::AGENT_UPDATED,
                        deactivationBlocker: fn (Agent $agent): ?string => $agent->is_default
                            ? 'O agente padrão não pode ser desativado; promova outro agente antes.'
                            : null,
                        after: fn () => app(DelegationService::class)->syncTools(),
                    ),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
