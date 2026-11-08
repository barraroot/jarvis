<?php

namespace App\Tools\Internal;

use App\Models\WhatsappConsent;
use App\Services\WhatsappGroupService;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;

/**
 * `whatsapp.groups.leave` (CT-30): leave a group.
 */
class WhatsappGroupsLeaveTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    public function identifier(): string
    {
        return 'whatsapp.groups.leave';
    }

    public function name(): string
    {
        return 'WhatsApp: sair do grupo';
    }

    public function description(): string
    {
        return 'Faz o WhatsApp do operador sair de um grupo. Exige aprovação.';
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
                'left' => ['type' => 'boolean'],
            ],
            'required' => ['group_jid', 'left'],
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

        app(WhatsappGroupService::class)->leave($session, $input['group_jid']);

        return ['group_jid' => $input['group_jid'], 'left' => true];
    }
}
