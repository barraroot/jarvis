<?php

namespace App\Filament\Resources\AiProviders\Schemas;

use App\Models\AiProvider;
use App\Models\AiProviderDriver;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProviderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificação')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Identificador usado em runtime para registrar o provedor.'),
                        Select::make('ai_provider_driver_id')
                            ->label('Driver')
                            ->options(AiProviderDriver::query()->active()->orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->helperText('Drivers suportados pelo laravel/ai.'),
                        TextInput::make('api_key')
                            ->label('Chave de API')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->nullable()
                            ->placeholder(fn (?AiProvider $record): ?string => $record?->masked_api_key)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->afterStateHydrated(function (TextInput $component): void {
                                $component->state(null);
                            })
                            ->helperText('Deixe em branco para manter a chave atual.')
                            ->columnSpanFull(),
                        TextInput::make('base_url')
                            ->label('URL base')
                            ->url()
                            ->nullable()
                            ->maxLength(255)
                            ->helperText('Opcional; em branco usa o endpoint padrão do driver.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Disponibilidade')
                    ->columns(1)
                    ->components([
                        Toggle::make('is_active')
                            ->label('Ativo')
                            ->helperText('Provedores inativos saem da seleção e da cadeia de fallback imediatamente.')
                            ->default(true),
                    ]),
            ]);
    }
}
