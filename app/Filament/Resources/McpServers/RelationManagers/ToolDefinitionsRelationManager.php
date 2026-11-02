<?php

namespace App\Filament\Resources\McpServers\RelationManagers;

use App\Models\AuditAction;
use App\Models\McpServer;
use App\Models\McpToolDefinition;
use App\Support\AuditLogger;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Tools discovered on a connector (US-9.3). Global connectors' tools are
 * classified here (write / sensitive / active); personal ones are read-only.
 */
class ToolDefinitionsRelationManager extends RelationManager
{
    protected static string $relationship = 'toolDefinitions';

    protected static ?string $title = 'Ferramentas descobertas';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn ($query) => $query->with('tool'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(),
                TextColumn::make('tool.identifier')
                    ->label('Identificador')
                    ->copyable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(60)
                    ->tooltip(fn (McpToolDefinition $record): ?string => $record->description),
                IconColumn::make('is_available')
                    ->label('Disponível')
                    ->boolean(),
                IconColumn::make('tool.is_write')
                    ->label('Escrita')
                    ->boolean(),
                IconColumn::make('tool.is_sensitive')
                    ->label('Sensível')
                    ->boolean(),
                IconColumn::make('tool.is_active')
                    ->label('Ativa')
                    ->boolean(),
                TextColumn::make('last_seen_at')
                    ->label('Vista em')
                    ->since(),
            ])
            ->recordActions([
                Action::make('classify')
                    ->label('Classificar')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->visible(fn (McpToolDefinition $record): bool => $this->ownerIsGlobal() && $record->tool !== null)
                    ->fillForm(fn (McpToolDefinition $record): array => [
                        'is_write' => $record->tool->is_write,
                        'is_sensitive' => $record->tool->is_sensitive,
                        'is_active' => $record->tool->is_active,
                    ])
                    ->schema([
                        Toggle::make('is_write')->label('Escrita (exige aprovação)'),
                        Toggle::make('is_sensitive')->label('Sensível (exige aprovação)'),
                        Toggle::make('is_active')
                            ->label('Ativa')
                            ->disabled(fn (McpToolDefinition $record): bool => ! $record->is_available),
                    ])
                    ->action(function (McpToolDefinition $record, array $data): void {
                        $tool = $record->tool;

                        $tool->fill([
                            'is_write' => (bool) $data['is_write'],
                            'is_sensitive' => (bool) $data['is_sensitive'],
                            'is_active' => $record->is_available && (bool) ($data['is_active'] ?? false),
                        ]);
                        $changes = array_keys($tool->getDirty());
                        $tool->save();

                        if ($changes !== []) {
                            app(AuditLogger::class)->log(AuditAction::TOOL_UPDATED, auth()->user(), $tool, [
                                'identifier' => $tool->identifier,
                                'changes' => $tool->only($changes),
                            ]);
                        }
                    }),
            ]);
    }

    private function ownerIsGlobal(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof McpServer && $owner->isGlobal();
    }
}
