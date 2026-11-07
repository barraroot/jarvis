<?php

namespace App\Services;

use App\Exceptions\WuzapiRequestException;
use App\Models\WhatsappSession;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * The only HTTP client of the wuzapi service (CT-20, field names checked
 * against `API.md`/`handlers.go` of the pinned tag v1.1.0). Admin calls carry
 * `Authorization: <admin token>`; session calls carry `Token: <user token>`
 * (bind one with `forSession()`). Every call uses a timeout no longer than
 * `brain.tool_timeout_seconds` (RNF-07); headers and bodies are never logged,
 * and failures become `WuzapiRequestException` with secrets redacted (RF-18).
 */
class WuzapiClient
{
    /**
     * Max length of the upstream detail kept on the exception.
     */
    public const int DETAIL_MAX_LENGTH = 300;

    public const string REDACTED = '[redacted]';

    /**
     * Event types the session webhook subscribes to (CT-09).
     *
     * @var list<string>
     */
    public const array WEBHOOK_EVENTS = ['Message', 'Connected', 'PairSuccess', 'Disconnected', 'LoggedOut'];

    private ?WhatsappSession $session = null;

    public function __construct(private readonly Settings $settings) {}

    /**
     * A client bound to the session's user token.
     */
    public function forSession(WhatsappSession $session): static
    {
        $client = clone $this;
        $client->session = $session;

        return $client;
    }

    /**
     * Timeout of every request: the configured one, capped by the tool timeout (RNF-07).
     */
    public function timeoutSeconds(): int
    {
        return max(1, min((int) config('services.wuzapi.timeout_seconds', 15), $this->settings->toolTimeoutSeconds()));
    }

    /**
     * `GET /health`: true when wuzapi answers 2xx.
     *
     * @throws WuzapiRequestException
     */
    public function health(): bool
    {
        $this->send('GET', '/health', admin: false, authenticated: false);

        return true;
    }

    /**
     * `POST /admin/users`: creates the dedicated wuzapi user and returns its id.
     *
     * @param  list<string>  $events
     *
     * @throws WuzapiRequestException
     */
    public function createUser(string $name, string $token, string $webhookUrl, array $events = self::WEBHOOK_EVENTS): string
    {
        $data = $this->admin('POST', '/admin/users', [
            'name' => $name,
            'token' => $token,
            'webhook' => $webhookUrl,
            'events' => implode(',', $events),
        ]);

        $id = $data['id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw new WuzapiRequestException('Resposta sem o id do usuário criado.');
        }

        return (string) $id;
    }

    /**
     * `DELETE /admin/users/{id}/full`: removes the user, its session and its files.
     *
     * @throws WuzapiRequestException
     */
    public function deleteUserFull(string $wuzapiUserId): void
    {
        $this->admin('DELETE', '/admin/users/'.rawurlencode($wuzapiUserId).'/full');
    }

    /**
     * `POST /session/connect`.
     *
     * @param  list<string>  $events
     * @return array<string, mixed>
     *
     * @throws WuzapiRequestException
     */
    public function connect(array $events = self::WEBHOOK_EVENTS, bool $immediate = true): array
    {
        return $this->session('POST', '/session/connect', ['Subscribe' => $events, 'Immediate' => $immediate]);
    }

    /**
     * @throws WuzapiRequestException
     */
    public function disconnect(): void
    {
        $this->session('POST', '/session/disconnect');
    }

    /**
     * `POST /session/logout`: ends the device link (a new pairing is required).
     *
     * @throws WuzapiRequestException
     */
    public function logout(): void
    {
        $this->session('POST', '/session/logout');
    }

    /**
     * `GET /session/status`.
     *
     * @return array{connected: bool, logged_in: bool}
     *
     * @throws WuzapiRequestException
     */
    public function status(): array
    {
        $data = $this->session('GET', '/session/status');

        return [
            'connected' => (bool) ($data['Connected'] ?? false),
            'logged_in' => (bool) ($data['LoggedIn'] ?? false),
        ];
    }

    /**
     * `GET /session/qr`: the QR code as a data URI, or null when none is available.
     * Never persist, log or broadcast it (RNF-10).
     *
     * @throws WuzapiRequestException
     */
    public function qr(): ?string
    {
        $qrCode = $this->session('GET', '/session/qr')['QRCode'] ?? null;

        return is_string($qrCode) && $qrCode !== '' ? $qrCode : null;
    }

    /**
     * `POST /session/pairphone`: the linking code for the phone. Never persist,
     * log or broadcast it (RNF-10).
     *
     * @throws WuzapiRequestException
     */
    public function pairPhone(string $phone): string
    {
        $linkingCode = $this->session('POST', '/session/pairphone', ['Phone' => $phone])['LinkingCode'] ?? null;

        if (! is_string($linkingCode) || $linkingCode === '') {
            throw new WuzapiRequestException('Resposta sem o código de pareamento.');
        }

        return $linkingCode;
    }

    /**
     * `POST /session/hmac/config`: the key (≥ 32 chars) signing the session webhooks.
     *
     * @throws WuzapiRequestException
     */
    public function configureHmac(string $hmacKey): void
    {
        $this->session('POST', '/session/hmac/config', ['hmac_key' => $hmacKey]);
    }

    /**
     * @internal Only `WhatsappOutboundService` may send (RF-19).
     *
     * @return string The upstream message id.
     *
     * @throws WuzapiRequestException
     */
    public function sendText(string $phone, string $body): string
    {
        return $this->sent($this->session('POST', '/chat/send/text', ['Phone' => $phone, 'Body' => $body]));
    }

    /**
     * @internal Only `WhatsappOutboundService` may send (RF-19).
     *
     * @param  string  $dataUri  `data:image/...;base64,...`
     *
     * @throws WuzapiRequestException
     */
    public function sendImage(string $phone, string $dataUri, ?string $caption = null): string
    {
        return $this->sent($this->session('POST', '/chat/send/image', array_filter([
            'Phone' => $phone,
            'Image' => $dataUri,
            'Caption' => $caption,
        ], fn (mixed $value): bool => $value !== null)));
    }

    /**
     * @internal Only `WhatsappOutboundService` may send (RF-19).
     *
     * @throws WuzapiRequestException
     */
    public function sendVideo(string $phone, string $dataUri, ?string $caption = null): string
    {
        return $this->sent($this->session('POST', '/chat/send/video', array_filter([
            'Phone' => $phone,
            'Video' => $dataUri,
            'Caption' => $caption,
        ], fn (mixed $value): bool => $value !== null)));
    }

    /**
     * @internal Only `WhatsappOutboundService` may send (RF-19).
     *
     * @throws WuzapiRequestException
     */
    public function sendAudio(string $phone, string $dataUri): string
    {
        return $this->sent($this->session('POST', '/chat/send/audio', ['Phone' => $phone, 'Audio' => $dataUri]));
    }

    /**
     * @internal Only `WhatsappOutboundService` may send (RF-19).
     *
     * @throws WuzapiRequestException
     */
    public function sendDocument(string $phone, string $dataUri, string $fileName, ?string $mimeType = null): string
    {
        return $this->sent($this->session('POST', '/chat/send/document', array_filter([
            'Phone' => $phone,
            'Document' => $dataUri,
            'FileName' => $fileName,
            'MimeType' => $mimeType,
        ], fn (mixed $value): bool => $value !== null)));
    }

    /**
     * @internal Only `WhatsappOutboundService` may send (RF-19).
     *
     * @throws WuzapiRequestException
     */
    public function sendLocation(string $phone, float $latitude, float $longitude, ?string $name = null): string
    {
        return $this->sent($this->session('POST', '/chat/send/location', array_filter([
            'Phone' => $phone,
            'Latitude' => $latitude,
            'Longitude' => $longitude,
            'Name' => $name,
        ], fn (mixed $value): bool => $value !== null)));
    }

    /**
     * `POST /chat/presence`: `composing` or `paused`; `$media = 'audio'` shows "recording".
     *
     * @throws WuzapiRequestException
     */
    public function chatPresence(string $phone, string $state, string $media = ''): void
    {
        $this->session('POST', '/chat/presence', ['Phone' => $phone, 'State' => $state, 'Media' => $media]);
    }

    /**
     * `POST /chat/markread` with full JIDs (legacy `Chat`/`Sender` fields).
     *
     * @param  list<string>  $messageIds
     *
     * @throws WuzapiRequestException
     */
    public function markRead(string $chatJid, array $messageIds, ?string $senderJid = null): void
    {
        $this->session('POST', '/chat/markread', array_filter([
            'Id' => array_values($messageIds),
            'Chat' => $chatJid,
            'Sender' => $senderJid,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $media  `Url`, `DirectPath`, `MediaKey`, `Mimetype`, `FileEncSHA256`, `FileSHA256`, `FileLength`
     * @return array{mimetype: string|null, data: string|null} `data` is a data URI; never stored (C-09).
     *
     * @throws WuzapiRequestException
     */
    public function downloadImage(array $media): array
    {
        return $this->download('/chat/downloadimage', $media);
    }

    /**
     * @param  array<string, mixed>  $media
     * @return array{mimetype: string|null, data: string|null}
     *
     * @throws WuzapiRequestException
     */
    public function downloadVideo(array $media): array
    {
        return $this->download('/chat/downloadvideo', $media);
    }

    /**
     * @param  array<string, mixed>  $media
     * @return array{mimetype: string|null, data: string|null}
     *
     * @throws WuzapiRequestException
     */
    public function downloadAudio(array $media): array
    {
        return $this->download('/chat/downloadaudio', $media);
    }

    /**
     * @param  array<string, mixed>  $media
     * @return array{mimetype: string|null, data: string|null}
     *
     * @throws WuzapiRequestException
     */
    public function downloadDocument(array $media): array
    {
        return $this->download('/chat/downloaddocument', $media);
    }

    /**
     * `GET /user/contacts`: contacts keyed by JID.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws WuzapiRequestException
     */
    public function contacts(): array
    {
        return $this->session('GET', '/user/contacts');
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws WuzapiRequestException
     */
    public function groupList(): array
    {
        return $this->session('GET', '/group/list');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws WuzapiRequestException
     */
    public function groupInfo(string $groupJid): array
    {
        return $this->session('GET', '/group/info', query: ['groupJID' => $groupJid]);
    }

    /**
     * @throws WuzapiRequestException
     */
    public function groupInviteLink(string $groupJid, bool $reset = false): string
    {
        $data = $this->session('GET', '/group/invitelink', query: [
            'groupJID' => $groupJid,
            'reset' => $reset ? 'true' : 'false',
        ]);

        return (string) ($data['InviteLink'] ?? '');
    }

    /**
     * @internal Only `WhatsappGroupService` may mutate groups (RF-15).
     *
     * @param  list<string>  $participants
     * @return array<string, mixed>
     *
     * @throws WuzapiRequestException
     */
    public function groupCreate(string $name, array $participants): array
    {
        return $this->session('POST', '/group/create', ['name' => $name, 'participants' => array_values($participants)]);
    }

    /**
     * @internal Only `WhatsappGroupService` may mutate groups (RF-15).
     *
     * @throws WuzapiRequestException
     */
    public function groupPhoto(string $groupJid, string $dataUri): void
    {
        $this->session('POST', '/group/photo', ['GroupJID' => $groupJid, 'Image' => $dataUri]);
    }

    /**
     * @internal Only `WhatsappGroupService` may mutate groups (RF-15).
     *
     * @throws WuzapiRequestException
     */
    public function groupLeave(string $groupJid): void
    {
        $this->session('POST', '/group/leave', ['GroupJID' => $groupJid]);
    }

    /**
     * @internal Only `WhatsappGroupService` may mutate groups (RF-15).
     *
     * @throws WuzapiRequestException
     */
    public function groupName(string $groupJid, string $name): void
    {
        $this->session('POST', '/group/name', ['GroupJID' => $groupJid, 'Name' => $name]);
    }

    /**
     * @internal Only `WhatsappGroupService` may mutate groups (RF-15).
     *
     * @throws WuzapiRequestException
     */
    public function groupTopic(string $groupJid, string $topic): void
    {
        $this->session('POST', '/group/topic', ['GroupJID' => $groupJid, 'Topic' => $topic]);
    }

    /**
     * @internal Only `WhatsappGroupService` may mutate groups (RF-15).
     *
     * @throws WuzapiRequestException
     */
    public function groupAnnounce(string $groupJid, bool $announce): void
    {
        $this->session('POST', '/group/announce', ['GroupJID' => $groupJid, 'Announce' => $announce]);
    }

    /**
     * @internal Only `WhatsappGroupService` may mutate groups (RF-15).
     *
     * @throws WuzapiRequestException
     */
    public function groupLocked(string $groupJid, bool $locked): void
    {
        $this->session('POST', '/group/locked', ['groupjid' => $groupJid, 'locked' => $locked]);
    }

    /**
     * @internal Only `WhatsappGroupService` may mutate groups (RF-15).
     *
     * @param  list<string>  $phones
     * @param  string  $action  `add`, `remove`, `promote` or `demote`
     * @return array<array-key, mixed>
     *
     * @throws WuzapiRequestException
     */
    public function groupUpdateParticipants(string $groupJid, array $phones, string $action): array
    {
        return $this->session('POST', '/group/updateparticipants', [
            'GroupJID' => $groupJid,
            'Phone' => array_values($phones),
            'Action' => $action,
        ]);
    }

    /**
     * @param  array<string, mixed>  $media
     * @return array{mimetype: string|null, data: string|null}
     */
    private function download(string $path, array $media): array
    {
        $data = $this->session('POST', $path, $media);

        return [
            'mimetype' => isset($data['Mimetype']) ? (string) $data['Mimetype'] : null,
            'data' => isset($data['Data']) ? (string) $data['Data'] : null,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function sent(array $data): string
    {
        $id = $data['Id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new WuzapiRequestException('Resposta de envio sem o id da mensagem.');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<array-key, mixed>
     */
    private function admin(string $method, string $path, ?array $body = null): array
    {
        return $this->send($method, $path, $body, admin: true);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $query
     * @return array<array-key, mixed>
     */
    private function session(string $method, string $path, ?array $body = null, array $query = []): array
    {
        if ($this->session === null) {
            throw new LogicException('Call forSession() before a session request to wuzapi.');
        }

        return $this->send($method, $path, $body, $query, admin: false);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $query
     * @return array<array-key, mixed> The `data` envelope (or the whole JSON when there is none).
     *
     * @throws WuzapiRequestException
     */
    private function send(string $method, string $path, ?array $body = null, array $query = [], bool $admin = false, bool $authenticated = true): array
    {
        $headers = match (true) {
            ! $authenticated => [],
            $admin => ['Authorization' => (string) config('services.wuzapi.admin_token')],
            default => ['Token' => (string) $this->session?->token],
        };

        $options = [];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.wuzapi.url'), '/'))
                ->acceptJson()
                ->withHeaders($headers)
                ->withoutRedirecting()
                ->timeout($this->timeoutSeconds())
                ->connectTimeout($this->timeoutSeconds())
                ->send($method, $path, $options);
        } catch (ConnectionException $exception) {
            throw new WuzapiRequestException($this->redact($exception->getMessage()));
        }

        if (! $response->successful()) {
            throw new WuzapiRequestException($this->redact($this->failureDetail($response)), $response->status());
        }

        $json = $response->json();

        if (! is_array($json)) {
            return [];
        }

        return is_array($json['data'] ?? null) ? $json['data'] : $json;
    }

    private function failureDetail(Response $response): string
    {
        $json = $response->json();
        $reason = is_array($json) ? ($json['error'] ?? $json['details'] ?? $json['Details'] ?? null) : null;

        $detail = is_string($reason) && $reason !== '' ? $reason : $response->body();

        return "HTTP {$response->status()}: {$detail}";
    }

    /**
     * Replace every known secret, then truncate (redacting first so no partial secret survives).
     */
    private function redact(string $detail): string
    {
        $secrets = array_filter([
            (string) config('services.wuzapi.admin_token'),
            (string) $this->session?->token,
            (string) $this->session?->hmac_key,
            (string) $this->session?->webhook_key,
        ], fn (string $secret): bool => $secret !== '');

        $detail = str_replace($secrets, self::REDACTED, $detail);

        return mb_strimwidth(trim($detail), 0, self::DETAIL_MAX_LENGTH, '…');
    }
}
