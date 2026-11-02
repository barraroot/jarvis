<?php

namespace App\Filament\Resources\AiModels\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class AiModelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('fallback_order')
            ->reorderable('fallback_order')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('model_identifier')
                    ->label('Identificador')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('provider.name')
                    ->label('Provedor')
                    ->sortable(),
                IconColumn::make('is_default_text')
                    ->label('Padrão texto')
                    ->boolean(),
                IconColumn::make('is_default_embedding')
                    ->label('Padrão embeddings')
                    ->boolean(),
                TextColumn::make('fallback_order')
                    ->label('Fallback')
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Ativo'),
                TernaryFilter::make('is_default_text')
                    ->label('Padrão de texto'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
