<?php

namespace Tests\Concerns;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the wuzapi service (CT-20) with `Http::fake` and
 * `preventStrayRequests` (RNF-08): every endpoint answers with a happy
 * default unless overridden by `"<METHOD> <path>"`. Also builds webhook
 * event payloads in the `WEBHOOK_FORMAT=json` shape.
 */
trait FakesWuzapi
{
    protected string $wuzapiUrl = 'http://wuzapi.test';

    protected string $wuzapiAdminToken = 'wuzapi-admin-token-for-tests';

    /**
     * Paths whose call means something left the device (message or group mutation).
     *
     * @var list<string>
     */
    protected array $wuzapiSendPaths = ['/chat/send/', '/group/create', '/group/updateparticipants'];

    /**
     * @param  array<string, array<array-key, mixed>|PromiseInterface|Closure(Request, array<string, mixed>): PromiseInterface>  $overrides
     *                                                                                                                                       keyed by `"<METHOD> <path>"`; an array is wrapped as the `data` of a 200 envelope
     */
    protected function fakeWuzapi(array $overrides = []): void
    {
        config([
            'services.wuzapi.url' => $this->wuzapiUrl,
            'services.wuzapi.admin_token' => $this->wuzapiAdminToken,
        ]);

        $responses = array_merge($this->wuzapiDefaultResponses(), $overrides);

        Http::preventStrayRequests();

        Http::fake([
            $this->wuzapiUrl.'/*' => function (Request $request, array $options) use ($responses) {
                $key = $request->method().' '.$this->wuzapiPath($request);
                $response = $responses[$key] ?? null;

                if ($response instanceof Closure) {
                    return $response($request, $options);
                }

                if (is_array($response)) {
                    return Http::response(['code' => 200, 'data' => $response, 'success' => true]);
                }

                return $response ?? Http::response(['code' => 404, 'error' => 'not found', 'success' => false], 404);
            },
        ]);
    }

    protected function assertWuzapiCalled(string $method, string $path, int $times = 1): void
    {
        $calls = Http::recorded(fn (Request $request): bool => $request->method() === strtoupper($method)
            && $this->wuzapiPath($request) === $path);

        $this->assertCount($times, $calls, "wuzapi [{$method} {$path}] was called {$calls->count()} time(s), expected {$times}.");
    }

    protected function assertNoWuzapiSend(): void
    {
        $sends = Http::recorded(function (Request $request): bool {
            $path = $this->wuzapiPath($request);

            foreach ($this->wuzapiSendPaths as $sendPath) {
                if (str_starts_with($path, $sendPath)) {
                    return true;
                }
            }

            return false;
        });

        $this->assertCount(0, $sends, 'wuzapi received a send or group mutation.');
    }

    /**
     * A `Message` webhook event.
     *
     * @param  array<string, mixed>  $overrides  merged recursively into the payload
     * @return array<string, mixed>
     */
    protected function wuzapiMessageEvent(array $overrides = []): array
    {
        return array_replace_recursive([
            'type' => 'Message',
            'event' => [
                'Info' => [
                    'ID' => '3EB0C431C26A1916E07E',
                    'Chat' => '5511988887777@s.whatsapp.net',
                    'Sender' => '5511988887777@s.whatsapp.net',
                    'IsFromMe' => false,
                    'IsGroup' => false,
                    'PushName' => 'Contato',
                    'Type' => 'text',
                    'Timestamp' => '2026-11-07T12:00:00-03:00',
                ],
                'Message' => [
                    'conversation' => 'Olá, tudo bem?',
                ],
            ],
            'token' => 'session-token',
        ], $overrides);
    }

    /**
     * A connection webhook event (`Connected`, `PairSuccess`, `Disconnected`, `LoggedOut`).
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    protected function wuzapiConnectionEvent(string $type = 'Connected', array $event = []): array
    {
        return [
            'type' => $type,
            'event' => $event,
            'token' => 'session-token',
        ];
    }

    /**
     * @return array<string, array<array-key, mixed>|PromiseInterface>
     */
    protected function wuzapiDefaultResponses(): array
    {
        $sent = ['Details' => 'Sent', 'Id' => '3EB0SENT0000000000001', 'Timestamp' => '2026-11-07T12:00:00-03:00'];

        return [
            'GET /health' => Http::response(['status' => 'ok']),
            'POST /admin/users' => Http::response(['code' => 201, 'data' => ['id' => 'wuzapi-user-1'], 'success' => true], 201),
            'DELETE /admin/users/wuzapi-user-1/full' => ['Details' => 'User deleted successfully'],
            'POST /session/connect' => ['details' => 'Connected!'],
            'POST /session/disconnect' => ['Details' => 'Disconnected'],
            'POST /session/logout' => ['Details' => 'Logged out'],
            'GET /session/status' => ['Connected' => true, 'LoggedIn' => true],
            'GET /session/qr' => ['QRCode' => 'data:image/png;base64,iVBORw0KGgo='],
            'POST /session/pairphone' => ['LinkingCode' => 'ABCD-EFGH'],
            'POST /session/hmac/config' => ['Details' => 'HMAC configuration saved successfully'],
            'POST /chat/send/text' => $sent,
            'POST /chat/send/image' => $sent,
            'POST /chat/send/video' => $sent,
            'POST /chat/send/audio' => $sent,
            'POST /chat/send/document' => $sent,
            'POST /chat/send/location' => $sent,
            'POST /chat/presence' => ['Details' => 'Chat presence set successfully'],
            'POST /chat/markread' => ['Details' => 'Message(s) marked as read'],
            'POST /chat/downloadimage' => ['Mimetype' => 'image/jpeg', 'Data' => 'data:image/jpeg;base64,/9j/4AAQ'],
            'POST /chat/downloadvideo' => ['Mimetype' => 'video/mp4', 'Data' => 'data:video/mp4;base64,AAAAIGZ0eXA='],
            'POST /chat/downloadaudio' => ['Mimetype' => 'audio/ogg', 'Data' => 'data:audio/ogg;base64,T2dnUw=='],
            'POST /chat/downloaddocument' => ['Mimetype' => 'application/pdf', 'Data' => 'data:application/pdf;base64,JVBERi0='],
            'GET /user/contacts' => [
                '5511988887777@s.whatsapp.net' => ['Found' => true, 'FullName' => 'Contato', 'PushName' => 'Contato'],
            ],
            'GET /group/list' => ['Groups' => []],
            'GET /group/info' => ['JID' => '120363000000000000@g.us', 'Name' => 'Grupo', 'Participants' => []],
            'GET /group/invitelink' => ['InviteLink' => 'https://chat.whatsapp.com/AbCdEf'],
            'POST /group/create' => ['JID' => '120363000000000000@g.us', 'Name' => 'Grupo'],
            'POST /group/photo' => ['Details' => 'Group Photo set successfully'],
            'POST /group/leave' => ['Details' => 'Group left successfully'],
            'POST /group/name' => ['Details' => 'Group Name set successfully'],
            'POST /group/topic' => ['Details' => 'Group Topic set successfully'],
            'POST /group/announce' => ['Details' => 'Group Announce set successfully'],
            'POST /group/locked' => ['Details' => 'Group Locked setting updated successfully'],
            'POST /group/updateparticipants' => ['Details' => 'Group Participants updated successfully'],
        ];
    }

    private function wuzapiPath(Request $request): string
    {
        return (string) parse_url($request->url(), PHP_URL_PATH);
    }
}
