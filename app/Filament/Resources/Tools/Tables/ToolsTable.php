<?php

namespace App\Filament\Resources\Tools\Tables;

use App\Models\AuditAction;
use App\Models\Tool;
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

class ToolsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('identifier')
            ->columns([
                TextColumn::make('identifier')
                    ->label('Identificador')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type.name')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_write')
                    ->label('Escrita')
                    ->boolean(),
                IconColumn::make('is_sensitive')
                    ->label('Sensível')
                    ->boolean(),
                TextColumn::make('timeout_seconds')
                    ->label('Timeout (s)')
                    ->numeric()
                    ->sortable(),
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
                    ->action(function (Tool $record): void {
                        $record->delete();

                        app(AuditLogger::class)->log(AuditAction::TOOL_DELETED, auth()->user(), $record, [
                            'identifier' => $record->identifier,
                            'name' => $record->name,
                        ]);
                    }),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
