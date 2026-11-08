<?php

namespace App\Tools\Internal;

use App\Models\WhatsappConsent;
use App\Services\WuzapiClient;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;

/**
 * `whatsapp.groups.invite_link` (CT-30): the current invite link of a group
 * (`GET /group/invitelink`). Sensitive — the link lets anyone join — so it
 * goes through the approval gate (RF-15); it never resets the link.
 */
class WhatsappGroupsInviteLinkTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    public function identifier(): string
    {
        return 'whatsapp.groups.invite_link';
    }

    public function name(): string
    {
        return 'WhatsApp: link de convite do grupo';
    }

    public function description(): string
    {
        return 'Obtém o link de convite atual de um grupo do WhatsApp do operador. Exige aprovação do operador.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'group_jid' => ['type' => 'string', 'pattern' => WhatsappGroupsInfoTool::GROUP_JID_PATTERN, 'maxLength' => 128, 'description' => 'JID do grupo (ex.: 120363000000000000@g.us).'],
            ],
            'required' => ['group_jid'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'group_jid' => ['type' => 'string'],
                'invite_link' => ['type' => 'string'],
            ],
            'required' => ['group_jid', 'invite_link'],
        ];
    }

    public function isSensitive(): bool
    {
        return true;
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
        return false;
    }

    public function handle(array $input, ToolContext $context): array
    {
        $session = $this->guardInvocation($input, $context);

        $link = $this->callWuzapi($session, fn (WuzapiClient $client): string => $client->groupInviteLink($input['group_jid']));

        return $this->untrusted([
            'group_jid' => $input['group_jid'],
            'invite_link' => $link,
        ]);
    }
}
