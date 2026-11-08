<?php

namespace App\Tools\Internal;

use App\Models\WhatsappConsent;
use App\Models\WhatsappMessage;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;
use Illuminate\Support\Str;

/**
 * `whatsapp.chats.list` (CT-30): the caller's chats with their last stored
 * message, newest first. Reads only stored messages — no wuzapi call.
 */
class WhatsappChatsListTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    public const int DEFAULT_LIMIT = 20;

    public const int MAX_LIMIT = 100;

    /**
     * Characters of the last message text returned per chat.
     */
    public const int PREVIEW_LENGTH = 300;

    public function identifier(): string
    {
        return 'whatsapp.chats.list';
    }

    public function name(): string
    {
        return 'WhatsApp: listar conversas';
    }

    public function description(): string
    {
        return 'Lista as conversas do WhatsApp do operador com a última mensagem armazenada, da mais recente para a mais antiga.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Máximo de conversas (padrão 20).'],
            ],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'chats' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'chat_jid' => ['type' => 'string'],
                            'is_group' => ['type' => 'boolean'],
                            'last_message_at' => ['type' => ['string', 'null']],
                            'last_message' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'id' => ['type' => 'integer'],
                                    'from_me' => ['type' => 'boolean'],
                                    'sender_jid' => ['type' => ['string', 'null']],
                                    'type' => ['type' => 'string'],
                                    'text' => ['type' => ['string', 'null']],
                                ],
                                'required' => ['id', 'from_me', 'sender_jid', 'type', 'text'],
                            ],
                        ],
                        'required' => ['chat_jid', 'is_group', 'last_message_at', 'last_message'],
                    ],
                ],
            ],
            'required' => ['chats'],
        ];
    }

    public function requiredScopes(array $input): array
    {
        return [WhatsappConsent::SCOPE_READ];
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
        $session = $this->guardInvocation($input, $context);

        $lastPerChat = WhatsappMessage::query()
            ->selectRaw('distinct on (chat_jid) id, chat_jid, sender_jid, from_me, type, text, sent_at')
            ->where('whatsapp_session_id', $session->id)
            ->orderBy('chat_jid')
            ->orderByDesc('sent_at')
            ->orderByDesc('id');

        $messages = WhatsappMessage::query()
            ->fromSub($lastPerChat, 'whatsapp_messages')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit((int) ($input['limit'] ?? self::DEFAULT_LIMIT))
            ->get();

        return $this->untrusted([
            'chats' => $messages->map(fn (WhatsappMessage $message): array => [
                'chat_jid' => $message->chat_jid,
                'is_group' => str_ends_with($message->chat_jid, '@g.us'),
                'last_message_at' => $message->sent_at?->toIso8601String(),
                'last_message' => [
                    'id' => $message->id,
                    'from_me' => $message->from_me,
                    'sender_jid' => $message->sender_jid,
                    'type' => $message->type,
                    'text' => $message->text === null ? null : Str::limit($message->text, self::PREVIEW_LENGTH),
                ],
            ])->all(),
        ]);
    }
}
