<?php

namespace App\Tools\Internal;

use App\Models\WhatsappConsent;
use App\Services\WhatsappGroupService;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Exceptions\ToolInputInvalidException;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;
use Illuminate\Support\Arr;

/**
 * `whatsapp.groups.update` (CT-30): name, description, photo (from an
 * `https` URL fetched by the server, RF-44), announce-only and locked
 * settings of a group.
 */
class WhatsappGroupsUpdateTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    /**
     * @var list<string>
     */
    public const array CHANGES = ['name', 'topic', 'photo_url', 'announce', 'locked'];

    public function identifier(): string
    {
        return 'whatsapp.groups.update';
    }

    public function name(): string
    {
        return 'WhatsApp: atualizar grupo';
    }

    public function description(): string
    {
        return 'Altera nome, descrição, foto (URL https pública), modo só-administradores ("announce") ou bloqueio de edição '
            .'("locked") de um grupo do WhatsApp do operador. Informe ao menos uma alteração. Exige aprovação.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'group_jid' => ['type' => 'string', 'pattern' => WhatsappGroupsInfoTool::GROUP_JID_PATTERN, 'maxLength' => 128, 'description' => 'JID do grupo (ex.: 120363000000000000@g.us).'],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => WhatsappGroupsCreateTool::MAX_NAME_LENGTH, 'description' => 'Novo nome.'],
                'topic' => ['type' => 'string', 'maxLength' => 2048, 'description' => 'Nova descrição.'],
                'photo_url' => ['type' => 'string', 'format' => 'uri', 'maxLength' => 2048, 'description' => 'URL https pública da nova foto.'],
                'announce' => ['type' => 'boolean', 'description' => 'Só administradores enviam mensagens.'],
                'locked' => ['type' => 'boolean', 'description' => 'Só administradores editam os dados do grupo.'],
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
                'updated' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['group_jid', 'updated'],
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
        $changes = Arr::only($input, self::CHANGES);

        if ($changes === []) {
            throw new ToolInputInvalidException($this->identifier(), ['at least one of '.implode(', ', self::CHANGES).' is required']);
        }

        $session = $this->guardInvocation($input, $context);

        return [
            'group_jid' => $input['group_jid'],
            'updated' => app(WhatsappGroupService::class)->update($session, $input['group_jid'], $changes),
        ];
    }
}
