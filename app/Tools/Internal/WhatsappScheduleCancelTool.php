<?php

namespace App\Tools\Internal;

use App\Models\WhatsappConsent;
use App\Models\WhatsappSchedule;
use App\Services\WhatsappScheduleService;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Exceptions\ToolInputInvalidException;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;

/**
 * `whatsapp.schedule.cancel` (CT-30): cancel one of the caller's schedules
 * (RF-33). Another user's schedule fails exactly like a missing one, so its
 * existence is not revealed. Cancelling only stops sends, so the kill switch
 * does not block it.
 */
class WhatsappScheduleCancelTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    public function identifier(): string
    {
        return 'whatsapp.schedule.cancel';
    }

    public function name(): string
    {
        return 'WhatsApp: cancelar agendamento';
    }

    public function description(): string
    {
        return 'Cancela um envio agendado (ativo ou pausado) do WhatsApp do operador pelo "id" de "whatsapp.schedule.list". '
            .'Ocorrências futuras não são executadas. Exige aprovação.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'schedule_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id do agendamento.'],
            ],
            'required' => ['schedule_id'],
        ];
    }

    public function outputSchema(): array
    {
        return WhatsappScheduleListTool::scheduleSchema();
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

    public function isWrite(): bool
    {
        return true;
    }

    public function handle(array $input, ToolContext $context): array
    {
        $this->guardInvocation($input, $context);

        $schedule = WhatsappSchedule::query()
            ->with('status')
            ->where('user_id', $context->user->id)
            ->find($input['schedule_id']);

        if ($schedule === null) {
            throw new ToolInputInvalidException($this->identifier(), ['schedule_id does not match any of your schedules']);
        }

        return WhatsappScheduleListTool::present(
            app(WhatsappScheduleService::class)->cancel($schedule, $context->user),
        );
    }
}
