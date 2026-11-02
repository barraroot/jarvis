<?php

namespace App\Filament\Resources\McpServers\Schemas;

use App\Http\Requests\Api\V1\Connectors\StoreConnectorRequest;
use App\Models\McpServer;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class McpServerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Conector')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(120)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule, ?McpServer $record): Unique => $record !== null && ! $record->isGlobal()
                                    ? $rule->where('user_id', $record->user_id)
                                    : $rule->whereNull('user_id'),
                            ),
                        TextInput::make('owner')
                            ->label('Escopo')
                            ->dehydrated(false)
                            ->disabled()
                            ->formatStateUsing(fn (?McpServer $record): string => $record === null || $record->isGlobal()
                                ? 'Global (todos os operadores)'
                                : 'Pessoal — '.$record->user?->name),
                        TextInput::make('url')
                            ->label('URL (HTTP/SSE)')
                            ->required()
                            ->maxLength(2048)
                            ->rules([fn () => StoreConnectorRequest::urlRule()])
                            ->helperText('Somente http(s)://; stdio e hosts privados não são aceitos.')
                            ->columnSpanFull(),
                        TextInput::make('credential')
                            ->label('Credencial (Bearer)')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->nullable()
                            ->maxLength(4096)
                            ->placeholder(fn (?McpServer $record): ?string => $record?->maskedCredential())
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->afterStateHydrated(function (TextInput $component): void {
                                $component->state(null);
                            })
                            ->helperText('Deixe em branco para manter a credencial atual.')
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Ativo')
                            ->default(true),
                    ]),
                Section::make('Status')
                    ->columns(2)
                    ->visibleOn(['edit', 'view'])
                    ->components([
                        Text::make(fn (?McpServer $record): string => 'Status: '.($record?->status?->name ?? '—')),
                        Text::make(fn (?McpServer $record): string => 'Última sincronização: '.($record?->last_synced_at?->format('d/m/Y H:i') ?? 'nunca')),
                        Text::make(fn (?McpServer $record): string => 'Última verificação: '.($record?->last_checked_at?->format('d/m/Y H:i') ?? 'nunca')),
                        Text::make(fn (?McpServer $record): string => 'Erro: '.($record?->last_error ?? '—')),
                    ]),
            ]);
    }
}
