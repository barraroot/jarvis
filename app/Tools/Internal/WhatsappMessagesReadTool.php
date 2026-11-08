<?php

namespace App\Tools\Internal;

use App\Models\WhatsappConsent;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSchedule;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Exceptions\ToolInputInvalidException;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * `whatsapp.messages.read` (CT-30): stored messages of one of the caller's
 * chats, newest first, filtered by period and paginated by an opaque
 * cursor. Media is described by its metadata only; the download keys never
 * leave the server (`whatsapp.media.download` uses them).
 */
class WhatsappMessagesReadTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 100;

    public function identifier(): string
    {
        return 'whatsapp.messages.read';
    }

    public function name(): string
    {
        return 'WhatsApp: ler mensagens';
    }

    public function description(): string
    {
        return 'Lê as mensagens armazenadas de uma conversa do WhatsApp do operador, da mais recente para a mais antiga. '
            .'Filtre por período com "after"/"before" (ISO 8601; sem fuso = America/Sao_Paulo) e use "next_cursor" '
            .'como "cursor" para a página seguinte.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'chat_jid' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 128, 'description' => 'JID da conversa (ex.: 5511999999999@s.whatsapp.net).'],
                'after' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Só mensagens a partir deste instante.'],
                'before' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Só mensagens antes deste instante.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Mensagens por página (padrão 50).'],
                'cursor' => ['type' => 'string', 'maxLength' => 512, 'description' => 'Cursor devolvido em "next_cursor".'],
            ],
            'required' => ['chat_jid'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'chat_jid' => ['type' => 'string'],
                'messages' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'upstream_id' => ['type' => 'string'],
                            'sender_jid' => ['type' => ['string', 'null']],
                            'from_me' => ['type' => 'boolean'],
                            'type' => ['type' => 'string'],
                            'text' => ['type' => ['string', 'null']],
                            'media' => ['type' => ['object', 'null']],
                            'sent_at' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['id', 'upstream_id', 'sender_jid', 'from_me', 'type', 'text', 'media', 'sent_at'],
                    ],
                ],
                'next_cursor' => ['type' => ['string', 'null']],
            ],
            'required' => ['chat_jid', 'messages', 'next_cursor'],
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

        $after = $this->moment($input, 'after');
        $before = $this->moment($input, 'before');

        $page = WhatsappMessage::query()
            ->where('whatsapp_session_id', $session->id)
            ->where('chat_jid', $input['chat_jid'])
            ->when($after !== null, fn ($query) => $query->where('sent_at', '>=', $after))
            ->when($before !== null, fn ($query) => $query->where('sent_at', '<', $before))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($input['limit'] ?? self::DEFAULT_LIMIT), ['*'], 'cursor', $input['cursor'] ?? null);

        return $this->untrusted([
            'chat_jid' => $input['chat_jid'],
            'messages' => collect($page->items())->map(fn (WhatsappMessage $message): array => [
                'id' => $message->id,
                'upstream_id' => $message->upstream_id,
                'sender_jid' => $message->sender_jid,
                'from_me' => $message->from_me,
                'type' => $message->type,
                'text' => $message->text,
                'media' => self::mediaSummary($message->media),
                'sent_at' => $message->sent_at?->toIso8601String(),
            ])->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /**
     * Media metadata without the download keys.
     *
     * @param  array<string, mixed>|null  $media
     * @return array<string, mixed>|null
     */
    public static function mediaSummary(?array $media): ?array
    {
        if ($media === null) {
            return null;
        }

        unset($media['download']);

        return $media;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ToolInputInvalidException
     */
    private function moment(array $input, string $key): ?CarbonImmutable
    {
        if (blank($input[$key] ?? null)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($input[$key], WhatsappSchedule::TIMEZONE)->utc();
        } catch (Throwable) {
            throw new ToolInputInvalidException($this->identifier(), ["{$key} must be an ISO 8601 date/time"]);
        }
    }
}
