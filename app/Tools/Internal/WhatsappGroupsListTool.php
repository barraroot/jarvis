<?php

namespace App\Tools\Internal;

use App\Models\WhatsappConsent;
use App\Services\WuzapiClient;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;

/**
 * `whatsapp.groups.list` (CT-30): the groups the caller's number belongs to
 * (`GET /group/list`).
 */
class WhatsappGroupsListTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    public function identifier(): string
    {
        return 'whatsapp.groups.list';
    }

    public function name(): string
    {
        return 'WhatsApp: listar grupos';
    }

    public function description(): string
    {
        return 'Lista os grupos do WhatsApp de que o número do operador participa, com nome, descrição e número de participantes.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'groups' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'jid' => ['type' => 'string'],
                            'name' => ['type' => ['string', 'null']],
                            'topic' => ['type' => ['string', 'null']],
                            'participant_count' => ['type' => 'integer'],
                            'is_announce' => ['type' => 'boolean'],
                            'is_locked' => ['type' => 'boolean'],
                        ],
                        'required' => ['jid', 'name', 'topic', 'participant_count', 'is_announce', 'is_locked'],
                    ],
                ],
            ],
            'required' => ['groups'],
        ];
    }

    public function requiredScopes(array $input): array
    {
        return [WhatsappConsent::SCOPE_READ, WhatsappConsent::SCOPE_GROUPS];
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

        $data = $this->callWuzapi($session, fn (WuzapiClient $client): array => $client->groupList());
        $groups = is_array($data['Groups'] ?? null) ? $data['Groups'] : [];

        return $this->untrusted([
            'groups' => collect($groups)
                ->filter(fn (mixed $group): bool => is_array($group) && is_string($group['JID'] ?? null))
                ->map(fn (array $group): array => [
                    'jid' => $group['JID'],
                    'name' => is_string($group['Name'] ?? null) ? $group['Name'] : null,
                    'topic' => is_string($group['Topic'] ?? null) ? $group['Topic'] : null,
                    'participant_count' => is_array($group['Participants'] ?? null) ? count($group['Participants']) : 0,
                    'is_announce' => (bool) ($group['IsAnnounce'] ?? false),
                    'is_locked' => (bool) ($group['IsLocked'] ?? false),
                ])
                ->values()
                ->all(),
        ]);
    }
}
