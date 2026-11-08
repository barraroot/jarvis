<?php

namespace App\Tools\Internal;

use App\Models\WhatsappConsent;
use App\Services\WhatsappGroupService;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;

/**
 * `whatsapp.groups.create` (CT-30): create a group with its initial
 * participants, each one counted in the daily cap and, when a non-contact,
 * in the non-contact limit (RF-29).
 */
class WhatsappGroupsCreateTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    /**
     * A participant: a phone number (8–15 digits, optional `+`) or a user JID.
     */
    public const string PARTICIPANT_PATTERN = '^(\+?[0-9]{8,15}|[0-9]{8,20}@s\.whatsapp\.net)$';

    public const int MAX_PARTICIPANTS = 50;

    public const int MAX_NAME_LENGTH = 100;

    /**
     * JSON Schema of a participant list, shared with `whatsapp.groups.participants`.
     *
     * @return array<string, mixed>
     */
    public static function participantsSchema(): array
    {
        return [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => self::MAX_PARTICIPANTS,
            'items' => ['type' => 'string', 'pattern' => self::PARTICIPANT_PATTERN, 'maxLength' => 64],
            'description' => 'Telefones com DDI (ex.: 5511999999999) ou JIDs de usuário.',
        ];
    }

    public function identifier(): string
    {
        return 'whatsapp.groups.create';
    }

    public function name(): string
    {
        return 'WhatsApp: criar grupo';
    }

    public function description(): string
    {
        return 'Cria um grupo no WhatsApp do operador com os participantes informados. Exige aprovação; '
            .'cada participante conta no teto diário de envios e, se não for contato, no limite de não-contatos.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_NAME_LENGTH, 'description' => 'Nome do grupo.'],
                'participants' => self::participantsSchema(),
            ],
            'required' => ['name', 'participants'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'jid' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'participants' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['jid', 'name', 'participants'],
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

        return $this->untrusted(
            app(WhatsappGroupService::class)->create($session, $input['name'], $input['participants'], $context->approval),
        );
    }
}
