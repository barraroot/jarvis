<?php

namespace App\Filament\Resources\AiModels\Schemas;

use App\Models\AiModelCapability;
use App\Models\AiProvider;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class AiModelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificação')
                    ->columns(2)
                    ->components([
                        Select::make('ai_provider_id')
                            ->label('Provedor')
                            ->relationship('provider', 'name')
                            ->options(AiProvider::query()->active()->orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->searchable(),
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('model_identifier')
                            ->label('Identificador do modelo')
                            ->required()
                            ->maxLength(255)
                            ->unique(modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('ai_provider_id', $get('ai_provider_id')), ignoreRecord: true)
                            ->helperText('Identificador esperado pelo provedor, ex.: gpt-4.1.'),
                        TextInput::make('context_window')
                            ->label('Janela de contexto')
                            ->numeric()
                            ->minValue(1)
                            ->nullable()
                            ->helperText('Em tokens; em branco = desconhecido.'),
                    ]),
                Section::make('Custos e capacidades')
                    ->columns(2)
                    ->components([
                        TextInput::make('input_cost_per_million')
                            ->label('Custo de entrada por 1M tokens')
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0)
                            ->step(0.0001)
                            ->required()
                            ->default(0),
                        TextInput::make('output_cost_per_million')
                            ->label('Custo de saída por 1M tokens')
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0)
                            ->step(0.0001)
                            ->required()
                            ->default(0),
                        CheckboxList::make('capabilities')
                            ->label('Capacidades')
                            ->relationship('capabilities', 'name')
                            ->columns(2)
                            ->required(),
                        TextInput::make('fallback_order')
                            ->label('Ordem de fallback')
                            ->numeric()
                            ->minValue(1)
                            ->nullable()
                            ->helperText('Em branco = fora da cadeia de fallback.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Padrões e disponibilidade')
                    ->columns(2)
                    ->components([
                        Toggle::make('is_default_text')
                            ->label('Padrão de texto')
                            ->helperText('Ao marcar, o padrão anterior é desmarcado automaticamente.')
                            ->default(false)
                            ->rule(self::requiresCapabilityRule(AiModelCapability::TEXT, 'text')),
                        Toggle::make('is_default_embedding')
                            ->label('Padrão de embeddings')
                            ->helperText('Ao marcar, o padrão anterior é desmarcado automaticamente.')
                            ->default(false)
                            ->rule(self::requiresCapabilityRule(AiModelCapability::EMBEDDINGS, 'embeddings')),
                        Toggle::make('is_default_realtime')
                            ->label('Padrão de voz (Realtime)')
                            ->helperText('Ao marcar, o padrão anterior é desmarcado automaticamente.')
                            ->default(false)
                            ->rule(self::requiresCapabilityRule(AiModelCapability::REALTIME, 'realtime')),
                        Toggle::make('is_active')
                            ->label('Ativo')
                            ->default(true),
                    ]),
            ]);
    }

    /**
     * A default flag is only valid when the model has the matching capability.
     */
    private static function requiresCapabilityRule(string $capabilitySlug, string $capabilityLabel): Closure
    {
        return fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $capabilitySlug, $capabilityLabel): void {
            if (! $value) {
                return;
            }

            $capabilityIds = array_map('intval', (array) $get('capabilities'));

            if (! in_array(AiModelCapability::idFor($capabilitySlug), $capabilityIds, true)) {
                $fail(sprintf('Um modelo padrão precisa ter a capacidade "%s".', $capabilityLabel));
            }
        };
    }
}
