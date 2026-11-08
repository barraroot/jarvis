<?php

namespace App\Tools\Internal;

use App\Models\WhatsappConsent;
use App\Services\WuzapiClient;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;

/**
 * `whatsapp.groups.info` (CT-30): details and participants of one group
 * (`GET /group/info`).
 */
class WhatsappGroupsInfoTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    /**
     * Group JIDs: `<digits>@g.us` (legacy groups carry `<creator>-<timestamp>`).
     */
    public const string GROUP_JID_PATTERN = '^[0-9]+(-[0-9]+)?@g\.us$';

    public function identifier(): string
    {
        return 'whatsapp.groups.info';
    }

    public function name(): string
    {
        return 'WhatsApp: detalhes do grupo';
    }

    public function description(): string
    {
        return 'Retorna nome, descrição, configurações e participantes (com administradores) de um grupo do WhatsApp do operador.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'group_jid' => ['type' => 'string', 'pattern' => self::GROUP_JID_PATTERN, 'maxLength' => 128, 'description' => 'JID do grupo (ex.: 120363000000000000@g.us).'],
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
                'jid' => ['type' => 'string'],
                'name' => ['type' => ['string', 'null']],
                'topic' => ['type' => ['string', 'null']],
                'owner_jid' => ['type' => ['string', 'null']],
                'is_announce' => ['type' => 'boolean'],
                'is_locked' => ['type' => 'boolean'],
                'created_at' => ['type' => ['string', 'null']],
                'participants' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'jid' => ['type' => 'string'],
                            'is_admin' => ['type' => 'boolean'],
                            'is_super_admin' => ['type' => 'boolean'],
                        ],
                        'required' => ['jid', 'is_admin', 'is_super_admin'],
                    ],
                ],
            ],
            'required' => ['jid', 'name', 'topic', 'owner_jid', 'is_announce', 'is_locked', 'created_at', 'participants'],
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

        $group = $this->callWuzapi($session, fn (WuzapiClient $client): array => $client->groupInfo($input['group_jid']));
        $participants = is_array($group['Participants'] ?? null) ? $group['Participants'] : [];

        return $this->untrusted([
            'jid' => is_string($group['JID'] ?? null) ? $group['JID'] : $input['group_jid'],
            'name' => self::stringOrNull($group['Name'] ?? null),
            'topic' => self::stringOrNull($group['Topic'] ?? null),
            'owner_jid' => self::stringOrNull($group['OwnerJID'] ?? null),
            'is_announce' => (bool) ($group['IsAnnounce'] ?? false),
            'is_locked' => (bool) ($group['IsLocked'] ?? false),
            'created_at' => self::stringOrNull($group['GroupCreated'] ?? null),
            'participants' => collect($participants)
                ->filter(fn (mixed $participant): bool => is_array($participant) && is_string($participant['JID'] ?? null))
                ->map(fn (array $participant): array => [
                    'jid' => $participant['JID'],
                    'is_admin' => (bool) ($participant['IsAdmin'] ?? false),
                    'is_super_admin' => (bool) ($participant['IsSuperAdmin'] ?? false),
                ])
                ->values()
                ->all(),
        ]);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
