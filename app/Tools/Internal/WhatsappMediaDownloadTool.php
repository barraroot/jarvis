<?php

namespace App\Tools\Internal;

use App\Exceptions\WhatsappOperationException;
use App\Models\WhatsappConsent;
use App\Models\WhatsappMessage;
use App\Services\WuzapiClient;
use App\Support\WhatsappMediaFetcher;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;
use RuntimeException;

/**
 * `whatsapp.media.download` (CT-30): fetches on demand, from wuzapi, the
 * media of one of the caller's stored messages using its metadata and hands
 * it to the agent as a data URI. Nothing is persisted (C-09); media above the
 * per-type limit (`services.wuzapi.media_max_bytes`) is refused.
 */
class WhatsappMediaDownloadTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    public function identifier(): string
    {
        return 'whatsapp.media.download';
    }

    public function name(): string
    {
        return 'WhatsApp: baixar mídia';
    }

    public function description(): string
    {
        return 'Baixa sob demanda a mídia (imagem, vídeo, áudio ou documento) de uma mensagem armazenada do WhatsApp do operador, '
            .'identificada pelo "id" devolvido por whatsapp.messages.read. Nada é guardado.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'message_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Campo "id" da mensagem em whatsapp.messages.read.'],
            ],
            'required' => ['message_id'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'message_id' => ['type' => 'integer'],
                'type' => ['type' => 'string', 'enum' => WhatsappMediaFetcher::KINDS],
                'mimetype' => ['type' => ['string', 'null']],
                'file_name' => ['type' => ['string', 'null']],
                'size' => ['type' => 'integer'],
                'data' => ['type' => 'string'],
            ],
            'required' => ['message_id', 'type', 'mimetype', 'file_name', 'size', 'data'],
        ];
    }

    public function requiredScopes(array $input): array
    {
        return [WhatsappConsent::SCOPE_READ, WhatsappConsent::SCOPE_MEDIA];
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

        $message = WhatsappMessage::query()
            ->where('whatsapp_session_id', $session->id)
            ->whereKey($input['message_id'])
            ->first();

        if ($message === null) {
            throw new RuntimeException(__('whatsapp.tools.message_not_found'));
        }

        $media = $message->media;

        if (! in_array($message->type, WhatsappMediaFetcher::KINDS, true) || ! is_array($media['download'] ?? null)) {
            throw new RuntimeException(__('whatsapp.tools.message_without_media'));
        }

        $maxBytes = (int) config("services.wuzapi.media_max_bytes.{$message->type}", 0);

        if ($maxBytes <= 0 || (int) ($media['size'] ?? 0) > $maxBytes) {
            throw new WhatsappOperationException(WhatsappOperationException::MEDIA_INVALID);
        }

        $downloaded = $this->callWuzapi($session, fn (WuzapiClient $client): array => match ($message->type) {
            'image' => $client->downloadImage($media['download']),
            'video' => $client->downloadVideo($media['download']),
            'audio' => $client->downloadAudio($media['download']),
            'document' => $client->downloadDocument($media['download']),
        });

        $data = (string) ($downloaded['data'] ?? '');
        $size = self::decodedSize($data);

        if ($data === '' || $size > $maxBytes) {
            throw new WhatsappOperationException(WhatsappOperationException::MEDIA_INVALID);
        }

        return $this->untrusted([
            'message_id' => $message->id,
            'type' => $message->type,
            'mimetype' => $downloaded['mimetype'] ?? ($media['mimetype'] ?? null),
            'file_name' => $media['file_name'] ?? null,
            'size' => $size,
            'data' => $data,
        ]);
    }

    /**
     * Bytes of a base64 data URI's payload.
     */
    private static function decodedSize(string $dataUri): int
    {
        $base64 = str_contains($dataUri, ',') ? substr($dataUri, strpos($dataUri, ',') + 1) : $dataUri;

        return intdiv(strlen($base64) * 3, 4) - substr_count(substr($base64, -2), '=');
    }
}
