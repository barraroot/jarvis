<?php

namespace App\Tools\Internal;

use App\Models\WhatsappConsent;
use App\Services\WhatsappGroupService;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;

/**
 * `whatsapp.groups.participants` (CT-30): add, remove, promote or demote
 * group participants; added ones count in the daily cap and the non-contact
 * limit (RF-29).
 */
class WhatsappGroupsParticipantsTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    public function identifier(): string
    {
        return 'whatsapp.groups.participants';
    }

    public function name(): string
    {
        return 'WhatsApp: participantes do grupo';
    }

    public function description(): string
    {
        return 'Adiciona, remove, promove a administrador ou rebaixa participantes de um grupo do WhatsApp do operador. '
            .'Exige aprovação; participantes adicionados contam no teto diário e, se não forem contatos, no limite de não-contatos.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'group_jid' => ['type' => 'string', 'pattern' => WhatsappGroupsInfoTool::GROUP_JID_PATTERN, 'maxLength' => 128, 'description' => 'JID do grupo (ex.: 120363000000000000@g.us).'],
                'action' => ['type' => 'string', 'enum' => WhatsappGroupService::PARTICIPANT_ACTIONS, 'description' => 'add (adicionar), remove (remover), promote (promover) ou demote (rebaixar).'],
                'participants' => WhatsappGroupsCreateTool::participantsSchema(),
            ],
            'required' => ['group_jid', 'action', 'participants'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'group_jid' => ['type' => 'string'],
                'action' => ['type' => 'string'],
                'participants' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['group_jid', 'action', 'participants'],
        ];
    }

    public function requiredScopes(array $input): array
    {
        return [WhatsappConsent::SCOPE_GROUPS];
    }

    public function callsUpstream(): bool
    {
        return true;
    }

    public function writesToWhatsapp(): bool
    {
        return true;
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function handle(array $input, ToolContext $context): array
    {
        $session = $this->guardInvocation($input, $context);

        $participants = app(WhatsappGroupService::class)->updateParticipants(
            $session,
            $input['group_jid'],
            $input['action'],
            $input['participants'],
            $context->approval,
        );

        return [
            'group_jid' => $input['group_jid'],
            'action' => $input['action'],
            'participants' => $participants,
        ];
    }
}
