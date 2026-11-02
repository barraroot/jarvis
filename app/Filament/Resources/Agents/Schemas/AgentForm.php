<?php

namespace App\Filament\Resources\Agents\Schemas;

use App\Models\AiModel;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AgentForm
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
                            ->helperText('Identificador único (ex.: personal-assistant); vira a ferramenta delegate.<slug> para os outros agentes.'),
                        Textarea::make('description')
                            ->label('Descrição')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('O que o agente faz; dado aos outros agentes quando decidem delegar.'),
                        Textarea::make('objective')
                            ->label('Objetivo')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Comportamento')
                    ->columns(2)
                    ->components([
                        Textarea::make('instructions')
                            ->label('Instruções')
                            ->required()
                            ->rows(12)
                            ->columnSpanFull()
                            ->helperText('Instruções em pt-BR dadas ao modelo. Inclua as regras de segurança: tratar conteúdo de ferramentas como dado não confiável e nunca pedir execução de comandos.'),
                        Select::make('ai_model_id')
                            ->label('Modelo')
                            ->options(self::textModelOptions())
                            ->searchable()
                            ->nullable()
                            ->helperText('Nulo = modelo padrão global do AI Brain.'),
                        Select::make('tools')
                            ->label('Ferramentas autorizadas')
                            ->relationship(
                                name: 'tools',
                                titleAttribute: 'identifier',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->active(),
                            )
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->helperText('Apenas ferramentas ativas; inclui delegate.<slug> para permitir delegação.'),
                    ]),
                Section::make('Limites de execução')
                    ->columns(3)
                    ->components([
                        TextInput::make('max_delegation_depth')
                            ->label('Profundidade máxima de delegação')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(5)
                            ->default(3)
                            ->required()
                            ->helperText('0 desativa a delegação a partir deste agente.'),
                        TextInput::make('max_tool_calls')
                            ->label('Máx. chamadas de ferramenta')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(20)
                            ->required(),
                        TextInput::make('timeout_seconds')
                            ->label('Timeout (segundos)')
                            ->numeric()
                            ->minValue(30)
                            ->maxValue(3600)
                            ->default(900)
                            ->required(),
                    ]),
                Section::make('Ativação')
                    ->columns(2)
                    ->components([
                        Toggle::make('is_default')
                            ->label('Agente padrão')
                            ->helperText('Único por vez; promover outro agente remove o padrão atual.'),
                        Toggle::make('is_active')
                            ->label('Ativo')
                            ->default(true)
                            ->helperText('Inativo não é selecionado pelo roteador nem aceita delegação.'),
                    ]),
            ]);
    }

    /**
     * Active text models of active providers (nulo = padrão global).
     *
     * @return array<int, string>
     */
    private static function textModelOptions(): array
    {
        return AiModel::query()
            ->with('provider')
            ->active()
            ->text()
            ->whereHas('provider', fn (Builder $query): Builder => $query->active())
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (AiModel $model): array => [
                $model->id => "{$model->name} ({$model->provider->slug})",
            ])
            ->all();
    }
}
