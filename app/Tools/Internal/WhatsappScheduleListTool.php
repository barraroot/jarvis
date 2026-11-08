<?php

namespace App\Tools\Internal;

use App\Models\WhatsappConsent;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappScheduleStatus;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;

/**
 * `whatsapp.schedule.list` (CT-30): the caller's WhatsApp schedules, next
 * run first. Local read: no wuzapi call, no `connected` session needed.
 * The message content is not exposed (same shape as CT-08).
 */
class WhatsappScheduleListTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 100;

    /**
     * JSON Schema of one schedule, shared with `whatsapp.schedule.create|cancel`.
     *
     * @return array<string, mixed>
     */
    public static function scheduleSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'id' => ['type' => 'integer'],
                'to' => ['type' => 'string'],
                'kind' => ['type' => 'string'],
                'run_at' => ['type' => ['string', 'null']],
                'recurrence' => ['type' => ['string', 'null']],
                'timezone' => ['type' => 'string'],
                'next_run_at' => ['type' => ['string', 'null']],
                'approval_expires_at' => ['type' => 'string'],
                'status' => ['type' => ['string', 'null']],
            ],
            'required' => ['id', 'to', 'kind', 'run_at', 'recurrence', 'timezone', 'next_run_at', 'approval_expires_at', 'status'],
        ];
    }

    /**
     * Expects `status` loaded.
     *
     * @return array<string, mixed>
     */
    public static function present(WhatsappSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'to' => $schedule->to_jid,
            'kind' => $schedule->kind,
            'run_at' => $schedule->run_at?->toIso8601String(),
            'recurrence' => $schedule->recurrence,
            'timezone' => WhatsappSchedule::TIMEZONE,
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'approval_expires_at' => $schedule->approval_expires_at->toIso8601String(),
            'status' => $schedule->statusSlug(),
        ];
    }

    public function identifier(): string
    {
        return 'whatsapp.schedule.list';
    }

    public function name(): string
    {
        return 'WhatsApp: listar agendamentos';
    }

    public function description(): string
    {
        return 'Lista os envios agendados do WhatsApp do operador (únicos e recorrentes), com status e próxima execução. '
            .'Horários em UTC; a recorrência (cron) é lida no fuso America/Sao_Paulo.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'status' => ['type' => 'string', 'enum' => [WhatsappScheduleStatus::ACTIVE, WhatsappScheduleStatus::PAUSED, WhatsappScheduleStatus::CANCELLED, WhatsappScheduleStatus::COMPLETED], 'description' => 'Filtra por status.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Máximo de agendamentos (padrão 50).'],
            ],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'schedules' => ['type' => 'array', 'items' => self::scheduleSchema()],
            ],
            'required' => ['schedules'],
        ];
    }

    public function requiredScopes(array $input): array
    {
        return [WhatsappConsent::SCOPE_SEND];
    }

    public function callsUpstream(): bool
    {
        return false;
    }

    public function writesToWhatsapp(): bool
    {
        return false;
    }

    public function handle(array $input, ToolContext $context): array
    {
        $this->guardInvocation($input, $context);

        $schedules = WhatsappSchedule::query()
            ->with('status')
            ->where('user_id', $context->user->id)
            ->when(isset($input['status']), fn ($query) => $query->whereRelation('status', 'slug', $input['status']))
            ->orderByRaw('next_run_at asc nulls last')
            ->orderByDesc('id')
            ->limit((int) ($input['limit'] ?? self::DEFAULT_LIMIT))
            ->get();

        return $this->untrusted([
            'schedules' => $schedules->map(fn (WhatsappSchedule $schedule): array => self::present($schedule))->all(),
        ]);
    }
}
