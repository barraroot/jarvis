<?php

namespace App\Services;

use App\Models\ConnectorStatus;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads the wuzapi webhook body (`WEBHOOK_FORMAT=json`: `{type, event, token}`,
 * tag v1.1.0) as untrusted data. Only the fields the receiver needs are
 * extracted; everything else is ignored. Media keeps metadata plus the keys
 * `/chat/download*` needs, never the binary (C-09).
 */
class WuzapiEventParser
{
    public const string MESSAGE = 'Message';

    /**
     * Connection event types and the session status they lead to (D15, RF-39).
     *
     * @var array<string, string>
     */
    public const array CONNECTION_STATUSES = [
        'Connected' => ConnectorStatus::CONNECTED,
        'PairSuccess' => ConnectorStatus::CONNECTED,
        'Disconnected' => ConnectorStatus::DISCONNECTED,
        'LoggedOut' => ConnectorStatus::DISCONNECTED,
    ];

    /**
     * Upstream message blocks stored as media, by stored type.
     *
     * @var array<string, string>
     */
    public const array MEDIA_BLOCKS = [
        'image' => 'imageMessage',
        'video' => 'videoMessage',
        'audio' => 'audioMessage',
        'document' => 'documentMessage',
    ];

    /**
     * Upstream media fields kept for the on-demand download (`WuzapiClient::download*`).
     *
     * @var array<string, string>
     */
    private const array DOWNLOAD_FIELDS = [
        'URL' => 'Url',
        'directPath' => 'DirectPath',
        'mediaKey' => 'MediaKey',
        'mimetype' => 'Mimetype',
        'fileEncSHA256' => 'FileEncSHA256',
        'fileSHA256' => 'FileSHA256',
        'fileLength' => 'FileLength',
    ];

    /**
     * @return array{type: string, event: array<array-key, mixed>}|null `null` when the body is not a JSON event.
     */
    public function parse(string $body): ?array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! isset($decoded['type']) || ! is_string($decoded['type'])) {
            return null;
        }

        return [
            'type' => $decoded['type'],
            'event' => is_array($decoded['event'] ?? null) ? $decoded['event'] : [],
        ];
    }

    /**
     * Session status a connection event leads to, or `null` for other events.
     */
    public function connectionStatus(string $type): ?string
    {
        return self::CONNECTION_STATUSES[$type] ?? null;
    }

    /**
     * Normalized attributes of a `Message` event, or `null` when it carries
     * nothing storable (reactions, protocol messages, unknown blocks).
     *
     * @param  array<array-key, mixed>  $event
     * @return array{upstream_id: string, chat_jid: string, sender_jid: string|null, from_me: bool, type: string, text: string|null, media: array<string, mixed>|null, sent_at: CarbonImmutable}|null
     */
    public function message(array $event): ?array
    {
        $info = is_array($event['Info'] ?? null) ? $event['Info'] : [];
        $content = is_array($event['Message'] ?? null) ? $event['Message'] : [];

        $upstreamId = $this->string($info['ID'] ?? null);
        $chatJid = $this->jid($info['Chat'] ?? null);

        if ($upstreamId === null || $chatJid === null || mb_strlen($upstreamId) > 128) {
            return null;
        }

        $body = $this->content($content);

        if ($body === null) {
            return null;
        }

        return [
            'upstream_id' => $upstreamId,
            'chat_jid' => $chatJid,
            'sender_jid' => $this->jid($info['Sender'] ?? null),
            'from_me' => ($info['IsFromMe'] ?? false) === true,
            'type' => $body['type'],
            'text' => $body['text'],
            'media' => $body['media'],
            'sent_at' => $this->timestamp($info['Timestamp'] ?? null),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $content
     * @return array{type: string, text: string|null, media: array<string, mixed>|null}|null
     */
    private function content(array $content): ?array
    {
        if (($text = $this->string($content['conversation'] ?? null)) !== null) {
            return ['type' => 'text', 'text' => $text, 'media' => null];
        }

        if (is_array($content['extendedTextMessage'] ?? null)
            && ($text = $this->string($content['extendedTextMessage']['text'] ?? null)) !== null) {
            return ['type' => 'text', 'text' => $text, 'media' => null];
        }

        foreach (self::MEDIA_BLOCKS as $type => $block) {
            if (is_array($content[$block] ?? null)) {
                $media = $this->media($content[$block]);

                return ['type' => $type, 'text' => $media['caption'], 'media' => $media];
            }
        }

        if (is_array($content['locationMessage'] ?? null)) {
            $location = $content['locationMessage'];

            return ['type' => 'location', 'text' => $this->string($location['name'] ?? null), 'media' => [
                'latitude' => is_numeric($location['degreesLatitude'] ?? null) ? (float) $location['degreesLatitude'] : null,
                'longitude' => is_numeric($location['degreesLongitude'] ?? null) ? (float) $location['degreesLongitude'] : null,
            ]];
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array{mimetype: string|null, size: int|null, caption: string|null, file_name: string|null, download: array<string, mixed>}
     */
    private function media(array $block): array
    {
        $download = [];

        foreach (self::DOWNLOAD_FIELDS as $upstream => $field) {
            if (isset($block[$upstream]) && (is_string($block[$upstream]) || is_int($block[$upstream]))) {
                $download[$field] = $block[$upstream];
            }
        }

        return [
            'mimetype' => $this->string($block['mimetype'] ?? null),
            'size' => is_numeric($block['fileLength'] ?? null) ? (int) $block['fileLength'] : null,
            'caption' => $this->string($block['caption'] ?? null),
            'file_name' => $this->string($block['fileName'] ?? null),
            'download' => $download,
        ];
    }

    /**
     * A JID without the device suffix (`5511…:12@s.whatsapp.net` → `5511…@s.whatsapp.net`).
     */
    private function jid(mixed $value): ?string
    {
        $jid = $this->string($value);

        if ($jid === null || mb_strlen($jid) > 255) {
            return null;
        }

        return preg_replace('/:\d+@/', '@', $jid);
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        if (is_string($value) && $value !== '') {
            try {
                return CarbonImmutable::parse($value)->utc();
            } catch (Throwable) {
                // Fall through to the reception time.
            }
        }

        return CarbonImmutable::now();
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
