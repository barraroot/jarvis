<?php

namespace App\Tools\Internal;

use App\Models\WhatsappConsent;
use App\Services\WuzapiClient;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;

/**
 * `whatsapp.messages.mark_read` (CT-30): mark messages of one chat as read
 * (`POST /chat/markread`). It changes what the contact sees, so it needs
 * approval and is blocked by the kill switch (RF-26).
 */
class WhatsappMessagesMarkReadTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    public const int MAX_MESSAGES = 100;

    public function identifier(): string
    {
        return 'whatsapp.messages.mark_read';
    }

    public function name(): string
    {
        return 'WhatsApp: marcar como lido';
    }

    public function description(): string
    {
        return 'Marca como lidas mensagens de uma conversa do WhatsApp do operador, pelos ids "upstream_id" de "whatsapp.messages.read". '
            .'Em grupos, informe também o remetente ("sender_jid"). Exige aprovação.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'chat_jid' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 128, 'description' => 'JID da conversa.'],
                'message_ids' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => self::MAX_MESSAGES,
                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                    'description' => 'Ids das mensagens ("upstream_id").',
                ],
                'sender_jid' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 128, 'description' => 'Remetente das mensagens (obrigatório em grupos).'],
            ],
            'required' => ['chat_jid', 'message_ids'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'chat_jid' => ['type' => 'string'],
                'marked' => ['type' => 'integer'],
            ],
            'required' => ['chat_jid', 'marked'],
        ];
    }

    public function requiredScopes(array $input): array
    {
        return [WhatsappConsent::SCOPE_READ];
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
        $messageIds = array_values(array_unique($input['message_ids']));

        $this->callWuzapi($session, fn (WuzapiClient $client) => $client->markRead($input['chat_jid'], $messageIds, $input['sender_jid'] ?? null));

        return ['chat_jid' => $input['chat_jid'], 'marked' => count($messageIds)];
    }
}
