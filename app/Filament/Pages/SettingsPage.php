<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Global limits and AI Brain parameters (US-12.2). Every key of
 * `Settings::RANGES` is editable with its range enforced and every key of
 * `Settings::OPTIONS` is a select of its closed list; saving goes through
 * `Settings::update()` (cache busted, `setting_updated` audited) so the new
 * values apply immediately, without deploy. The WhatsApp brakes (CT-40) are
 * also checked against `Settings::CROSS_RULES` before anything is persisted,
 * and the kill switch is a toggle stored as 0/1 (RF-26, RF-42).
 *
 * @property-read Schema $form
 */
class SettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Configurações';

    protected static ?string $title = 'Configurações do AI Brain e limites';

    protected static ?string $slug = 'settings';

    /**
     * Form state, nested by key segment (`brain.max_tool_calls` → `data.brain.max_tool_calls`).
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Field labels per setting key.
     *
     * @var array<string, string>
     */
    private const array LABELS = [
        'brain.max_delegation_depth' => 'Profundidade máxima de delegação',
        'brain.tool_timeout_seconds' => 'Timeout de ferramenta (s)',
        'brain.max_tool_calls' => 'Chamadas de ferramenta por execução',
        'brain.task_timeout_seconds' => 'Duração máxima de tarefa (s)',
        'brain.messages_per_minute' => 'Mensagens por minuto (por usuário)',
        'brain.approval_ttl_hours' => 'Expiração de aprovação (h)',
        'brain.context_messages' => 'Mensagens de contexto',
        'memory.top_n' => 'Memórias recuperadas (top-N)',
        'memory.similarity_threshold' => 'Limiar de similaridade',
        'memory.duplicate_threshold' => 'Limiar de duplicidade',
        'connector.health_check_minutes' => 'Intervalo do health check (min)',
        'retention.tool_executions_days' => 'Retenção de execuções de ferramentas (dias)',
        'retention.task_logs_days' => 'Retenção de logs de tarefas (dias)',
        'retention.usage_records_months' => 'Retenção de registros de consumo (meses)',
        'retention.read_notifications_days' => 'Retenção de notificações lidas (dias)',
        'retention.whatsapp_messages_days' => 'Retenção de mensagens WhatsApp (dias)',
        'voice.listening_window_seconds' => 'Janela de escuta (s)',
        'voice.session_token_ceiling' => 'Teto de tokens por sessão de voz',
        'voice.realtime_voice' => 'Voz do assistente (Realtime)',
        'whatsapp.kill_switch' => 'Kill switch (bloqueia toda escrita e entrega no WhatsApp)',
        'whatsapp.max_sends_per_minute' => 'Envios por minuto (por sessão)',
        'whatsapp.max_sends_per_recipient_per_hour' => 'Envios por destinatário por hora',
        'whatsapp.daily_send_cap' => 'Teto diário de envios (por sessão)',
        'whatsapp.min_send_interval_seconds' => 'Intervalo mínimo entre envios (s)',
        'whatsapp.jitter_min_seconds' => 'Jitter mínimo (s)',
        'whatsapp.jitter_max_seconds' => 'Jitter máximo (s)',
        'whatsapp.typing_min_ms' => 'Digitando — duração mínima (ms)',
        'whatsapp.typing_max_ms' => 'Digitando — duração máxima (ms)',
        'whatsapp.max_non_contact_recipients' => 'Destinatários fora dos contatos por janela',
        'whatsapp.non_contact_window_minutes' => 'Janela de destinatários fora dos contatos (min)',
    ];

    /**
     * Keys rendered as an on/off toggle, stored as 0/1.
     *
     * @var list<string>
     */
    private const array TOGGLES = ['whatsapp.kill_switch'];

    /**
     * Section title per key prefix.
     *
     * @var array<string, string>
     */
    private const array SECTIONS = [
        'brain' => 'AI Brain',
        'memory' => 'Memória',
        'connector' => 'Conectores',
        'retention' => 'Retenção de dados',
        'voice' => 'Voz (Realtime)',
        'whatsapp' => 'WhatsApp — freios e kill switch',
    ];

    public function mount(): void
    {
        $settings = app(Settings::class);
        $values = [];

        foreach (self::settingKeys() as $key) {
            $values[$key] = $settings->get($key, Settings::WHATSAPP_DEFAULTS[$key] ?? null);
        }

        foreach (self::TOGGLES as $key) {
            $values[$key] = (bool) $values[$key];
        }

        $this->form->fill(Arr::undot($values));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components(collect(self::SECTIONS)
                ->map(fn (string $title, string $prefix): Section => Section::make($title)
                    ->columns(2)
                    ->schema(collect(self::settingKeys())
                        ->filter(fn (string $key): bool => str_starts_with($key, $prefix.'.'))
                        ->map(fn (string $key): Field => match (true) {
                            in_array($key, self::TOGGLES, true) => $this->toggleField($key),
                            array_key_exists($key, Settings::OPTIONS) => $this->optionField($key, Settings::OPTIONS[$key]),
                            default => $this->field($key, Settings::RANGES[$key]),
                        })
                        ->values()
                        ->all()))
                ->values()
                ->all());
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Salvar')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $values = Arr::only(Arr::dot($this->form->getState()), self::settingKeys());

        foreach (self::TOGGLES as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = $values[$key] ? 1 : 0;
            }
        }

        $settings = app(Settings::class);
        $crossFieldErrors = $settings->crossFieldErrors($values);

        if ($crossFieldErrors !== []) {
            throw ValidationException::withMessages(collect($crossFieldErrors)
                ->mapWithKeys(fn (string $message, string $key): array => ["data.{$key}" => $message])
                ->all());
        }

        $changed = $settings->update($values, auth()->user());

        Notification::make()
            ->success()
            ->title($changed === [] ? 'Nenhuma alteração' : 'Configurações salvas')
            ->send();
    }

    /**
     * Every editable setting key: numeric ranges followed by closed option lists.
     *
     * @return list<string>
     */
    private static function settingKeys(): array
    {
        return [...array_keys(Settings::RANGES), ...array_keys(Settings::OPTIONS)];
    }

    private function toggleField(string $key): Toggle
    {
        return Toggle::make($key)
            ->label(self::LABELS[$key] ?? $key)
            ->helperText('Ativo: nenhuma ferramenta escreve no WhatsApp e envios na fila ficam bloqueados.');
    }

    /**
     * @param  list<string>  $options
     */
    private function optionField(string $key, array $options): Select
    {
        return Select::make($key)
            ->label(self::LABELS[$key] ?? $key)
            ->required()
            ->native(false)
            ->options(array_combine($options, $options))
            ->in($options);
    }

    /**
     * @param  array{min: int|float, max: int|float|null, type: 'int'|'float'}  $range
     */
    private function field(string $key, array $range): TextInput
    {
        $input = TextInput::make($key)
            ->label(self::LABELS[$key] ?? $key)
            ->required()
            ->numeric()
            ->minValue($range['min'])
            ->helperText($range['max'] === null
                ? sprintf('Mínimo %s.', $range['min'])
                : sprintf('Entre %s e %s.', $range['min'], $range['max']));

        if ($range['max'] !== null) {
            $input->maxValue($range['max']);
        }

        if ($range['type'] === 'int') {
            $input->integer();
        } else {
            $input->step(0.01);
        }

        return $input;
    }
}
