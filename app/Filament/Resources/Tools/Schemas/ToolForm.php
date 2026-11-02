<?php

namespace App\Filament\Resources\Tools\Schemas;

use App\Models\Tool;
use App\Models\ToolType;
use App\Support\JsonSchemaValidator;
use App\Tools\InternalToolRegistry;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ToolForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificação')
                    ->columns(2)
                    ->components([
                        Select::make('tool_type_id')
                            ->label('Tipo')
                            ->options(ToolType::query()->active()->orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->live()
                            ->disabled(self::isMcpTool(...))
                            ->helperText('internal: classe Laravel; mcp: descoberta em conector; rest/webhook: chamada HTTP.'),
                        TextInput::make('identifier')
                            ->label('Identificador')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->disabled(self::isMcpTool(...))
                            ->helperText('Nome único oferecido aos modelos (ex.: erp.list_overdue_customers).'),
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255)
                            ->disabled(self::isMcpTool(...)),
                        Textarea::make('description')
                            ->label('Descrição')
                            ->required()
                            ->rows(3)
                            ->disabled(self::isMcpTool(...))
                            ->helperText('Propósito da ferramenta, dado ao modelo para ele decidir quando usá-la.')
                            ->columnSpanFull(),
                        Textarea::make('input_schema')
                            ->label('Schema de entrada (JSON Schema)')
                            ->required()
                            ->rows(10)
                            ->formatStateUsing(self::encodeSchema(...))
                            ->afterStateHydrated(self::decodeSchema(...))
                            ->rules([self::jsonSchemaRule()])
                            ->disabled(self::isMcpTool(...))
                            ->helperText('Valida os parâmetros antes da execução (type, properties, required, enum, bounds...).')
                            ->columnSpanFull(),
                        Textarea::make('output_schema')
                            ->label('Schema de saída (JSON Schema)')
                            ->required()
                            ->rows(10)
                            ->formatStateUsing(self::encodeSchema(...))
                            ->afterStateHydrated(self::decodeSchema(...))
                            ->rules([self::jsonSchemaRule()])
                            ->disabled(self::isMcpTool(...))
                            ->helperText('A saída do handler é validada contra este schema.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Execução')
                    ->columns(2)
                    ->components([
                        TextInput::make('timeout_seconds')
                            ->label('Timeout (segundos)')
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(300)
                            ->default(30)
                            ->disabled(self::isMcpTool(...))
                            ->helperText('Entre 5 e 300 segundos; o padrão global é 30.'),
                        Select::make('handler_class')
                            ->label('Classe interna')
                            ->options(app(InternalToolRegistry::class)->classes())
                            ->searchable()
                            ->nullable()
                            ->visible(fn (Get $get): bool => (int) $get('tool_type_id') === ToolType::idFor(ToolType::INTERNAL))
                            ->disabled(self::isMcpTool(...))
                            ->helperText('Classes descobertas em app/Tools/Internal; sincronizadas por jarvis:tools:sync-internal.'),
                        TextInput::make('endpoint_url')
                            ->label('URL do endpoint')
                            ->url()
                            ->nullable()
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => in_array((int) $get('tool_type_id'), self::httpTypeIds(), true))
                            ->disabled(self::isMcpTool(...)),
                        Select::make('http_method')
                            ->label('Método HTTP')
                            ->options(['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE'])
                            ->default('POST')
                            ->nullable()
                            ->visible(fn (Get $get): bool => in_array((int) $get('tool_type_id'), self::httpTypeIds(), true))
                            ->disabled(self::isMcpTool(...)),
                        TextInput::make('credential')
                            ->label('Credencial')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->nullable()
                            ->placeholder(fn (?Tool $record): ?string => $record?->masked_credential)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->afterStateHydrated(function (TextInput $component): void {
                                $component->state(null);
                            })
                            ->visible(fn (Get $get): bool => in_array((int) $get('tool_type_id'), self::httpTypeIds(), true))
                            ->disabled(self::isMcpTool(...))
                            ->helperText('Enviada no header Authorization (ou no header declarado em input_schema.x-auth). Deixe em branco para manter.')
                            ->columnSpanFull(),
                        Toggle::make('is_write')
                            ->label('Escrita')
                            ->default(true)
                            ->helperText('Ferramentas de escrita exigem aprovação do dono antes de executar.'),
                        Toggle::make('is_sensitive')
                            ->label('Sensível')
                            ->default(true)
                            ->helperText('Ferramentas sensíveis exigem aprovação do dono antes de executar.'),
                    ]),
                Section::make('Disponibilidade')
                    ->columns(1)
                    ->components([
                        Toggle::make('is_active')
                            ->label('Ativo')
                            ->helperText('Ferramentas inativas saem imediatamente da lista oferecida aos modelos.')
                            ->default(true),
                    ]),
            ]);
    }

    /**
     * MCP tools are managed by connector sync: everything but the
     * classification and activation is read-only (US-9.3).
     */
    private static function isMcpTool(?Tool $record): bool
    {
        return $record !== null && $record->type->slug === ToolType::MCP;
    }

    /**
     * @return list<int>
     */
    private static function httpTypeIds(): array
    {
        return [ToolType::idFor(ToolType::REST), ToolType::idFor(ToolType::WEBHOOK)];
    }

    private static function encodeSchema(?array $state): ?string
    {
        if ($state === null || $state === []) {
            return null;
        }

        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    private static function decodeSchema(Textarea $component, array|string|null $state): void
    {
        if (is_array($state)) {
            return;
        }

        if (blank($state)) {
            $component->state(null);

            return;
        }

        $decoded = json_decode($state, true);

        $component->state(is_array($decoded) ? $decoded : null);
    }

    private static function jsonSchemaRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                $fail('O schema deve ser um objeto JSON válido.');

                return;
            }

            if (! app(JsonSchemaValidator::class)->isValidSchema($value)) {
                $fail('Não é um JSON Schema suportado: informe ao menos um "type" válido (object, string, integer, number, boolean, array).');
            }
        };
    }
}
