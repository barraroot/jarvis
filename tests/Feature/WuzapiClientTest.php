<?php

namespace Tests\Feature;

use App\Exceptions\WuzapiRequestException;
use App\Models\WhatsappSession;
use App\Services\WuzapiClient;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WuzapiClientTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private const string SESSION_TOKEN = 'session-token-0123456789abcdef';

    private const string HMAC_KEY = 'hmac-key-0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
    }

    private function sessionClient(): WuzapiClient
    {
        $session = WhatsappSession::factory()->create([
            'token' => self::SESSION_TOKEN,
            'hmac_key' => self::HMAC_KEY,
        ]);

        return app(WuzapiClient::class)->forSession($session);
    }

    public function test_admin_calls_carry_authorization_and_session_calls_carry_token(): void
    {
        $this->fakeWuzapi();

        $id = app(WuzapiClient::class)->createUser('jarvis-user-1', self::SESSION_TOKEN, 'http://laravel.test/api/v1/webhooks/wuzapi/key', ['Message']);
        $this->assertSame('wuzapi-user-1', $id);

        $client = $this->sessionClient();
        $this->assertSame(['connected' => true, 'logged_in' => true], $client->status());
        $this->assertSame('3EB0SENT0000000000001', $client->sendText('5511988887777@s.whatsapp.net', 'Olá'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wuzapi.test/admin/users'
            && $request->hasHeader('Authorization', $this->wuzapiAdminToken)
            && ! $request->hasHeader('Token')
            && $request['name'] === 'jarvis-user-1'
            && $request['token'] === self::SESSION_TOKEN
            && $request['webhook'] === 'http://laravel.test/api/v1/webhooks/wuzapi/key'
            && $request['events'] === 'Message');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wuzapi.test/session/status'
            && $request->hasHeader('Token', self::SESSION_TOKEN)
            && ! $request->hasHeader('Authorization'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wuzapi.test/chat/send/text'
            && $request->hasHeader('Token', self::SESSION_TOKEN)
            && $request['Phone'] === '5511988887777@s.whatsapp.net'
            && $request['Body'] === 'Olá');

        $this->assertWuzapiCalled('POST', '/admin/users');
        $this->assertWuzapiCalled('GET', '/session/status');
        $this->assertWuzapiCalled('POST', '/chat/send/text');
    }

    public function test_session_endpoints_map_upstream_fields(): void
    {
        $this->fakeWuzapi();
        $client = $this->sessionClient();

        $this->assertSame('data:image/png;base64,iVBORw0KGgo=', $client->qr());
        $this->assertSame('ABCD-EFGH', $client->pairPhone('5511988887777'));
        $client->configureHmac(self::HMAC_KEY);
        $client->connect();
        $client->chatPresence('5511988887777@s.whatsapp.net', 'composing');
        $client->markRead('5511988887777@s.whatsapp.net', ['ABC']);
        $this->assertSame('image/jpeg', $client->downloadImage(['Url' => 'https://mmg.whatsapp.net/x'])['mimetype']);
        $this->assertArrayHasKey('5511988887777@s.whatsapp.net', $client->contacts());
        $this->assertSame('https://chat.whatsapp.com/AbCdEf', $client->groupInviteLink('120363000000000000@g.us'));
        $client->groupLocked('120363000000000000@g.us', true);
        app(WuzapiClient::class)->deleteUserFull('wuzapi-user-1');
        $this->assertTrue(app(WuzapiClient::class)->health());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wuzapi.test/session/hmac/config'
            && $request['hmac_key'] === self::HMAC_KEY);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://wuzapi.test/group/invitelink?')
            && str_contains($request->url(), 'groupJID=120363000000000000%40g.us'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wuzapi.test/group/locked'
            && $request['groupjid'] === '120363000000000000@g.us'
            && $request['locked'] === true);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wuzapi.test/chat/markread'
            && $request['Id'] === ['ABC']
            && $request['Chat'] === '5511988887777@s.whatsapp.net');
        $this->assertWuzapiCalled('DELETE', '/admin/users/wuzapi-user-1/full');
        $this->assertWuzapiCalled('GET', '/health');
    }

    public function test_timeout_never_exceeds_the_tool_timeout_setting(): void
    {
        config(['services.wuzapi.timeout_seconds' => 60]);
        app(Settings::class)->set('brain.tool_timeout_seconds', 12);

        $this->assertSame(12, app(WuzapiClient::class)->timeoutSeconds());

        config(['services.wuzapi.timeout_seconds' => 5]);
        $this->assertSame(5, app(WuzapiClient::class)->timeoutSeconds());

        config(['services.wuzapi.timeout_seconds' => 60]);
        $observedTimeout = null;
        $this->fakeWuzapi([
            'GET /session/status' => function (Request $request, array $options) use (&$observedTimeout) {
                $observedTimeout = $options['timeout'] ?? null;

                return Http::response(['code' => 200, 'data' => ['connected' => false, 'loggedIn' => false]]);
            },
        ]);

        $this->sessionClient()->status();

        $this->assertNotNull($observedTimeout);
        $this->assertLessThanOrEqual(12, $observedTimeout);
    }

    public function test_upstream_error_never_leaks_secrets(): void
    {
        Log::spy();

        $this->fakeWuzapi([
            'POST /chat/send/text' => Http::response(
                'panic: invalid token '.self::SESSION_TOKEN.' admin '.$this->wuzapiAdminToken.' hmac '.self::HMAC_KEY.' '.str_repeat('x', 500),
                500,
            ),
        ]);

        try {
            $this->sessionClient()->sendText('5511988887777@s.whatsapp.net', 'Olá');
            $this->fail('Expected WuzapiRequestException.');
        } catch (WuzapiRequestException $exception) {
            $this->assertSame(500, $exception->status);
            $this->assertSame(__('whatsapp.errors.whatsapp_upstream_error'), $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringContainsString('[redacted]', $exception->upstreamDetail);
            $this->assertLessThanOrEqual(WuzapiClient::DETAIL_MAX_LENGTH, mb_strlen($exception->upstreamDetail));

            foreach ([self::SESSION_TOKEN, $this->wuzapiAdminToken, self::HMAC_KEY] as $secret) {
                $this->assertStringNotContainsString($secret, $exception->getMessage());
                $this->assertStringNotContainsString($secret, $exception->upstreamDetail);
            }
        }

        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_connection_failure_and_missing_fields_become_request_exceptions(): void
    {
        $this->fakeWuzapi([
            'GET /session/status' => fn () => throw new ConnectionException('cURL error 28: Operation timed out for token '.self::SESSION_TOKEN),
            'POST /chat/send/text' => ['Details' => 'Sent'],
        ]);
        $client = $this->sessionClient();

        try {
            $client->status();
            $this->fail('Expected WuzapiRequestException.');
        } catch (WuzapiRequestException $exception) {
            $this->assertNull($exception->status);
            $this->assertStringContainsString('timed out', $exception->upstreamDetail);
            $this->assertStringNotContainsString(self::SESSION_TOKEN, $exception->upstreamDetail);
        }

        $this->expectException(WuzapiRequestException::class);
        $client->sendText('5511988887777@s.whatsapp.net', 'Olá');
    }

    public function test_connect_treats_already_connected_as_success_but_surfaces_other_errors(): void
    {
        $statuses = [409, 500];
        $this->fakeWuzapi([
            'POST /session/connect' => function () use (&$statuses) {
                $status = array_shift($statuses);

                return Http::response(['code' => $status, 'error' => 'already connected', 'success' => false], $status);
            },
        ]);
        $client = $this->sessionClient();

        $this->assertSame([], $client->connect());

        try {
            $client->connect();
            $this->fail('Expected WuzapiRequestException.');
        } catch (WuzapiRequestException $exception) {
            $this->assertSame(500, $exception->status);
        }
    }

    public function test_unfaked_request_fails(): void
    {
        $this->fakeWuzapi();
        config(['services.wuzapi.url' => 'http://not-faked.test']);

        $this->expectException(StrayRequestException::class);

        app(WuzapiClient::class)->health();
    }

    public function test_assert_no_wuzapi_send_ignores_reads(): void
    {
        $this->fakeWuzapi();
        $client = $this->sessionClient();

        $client->status();
        $client->contacts();

        $this->assertNoWuzapiSend();
        $this->assertWuzapiCalled('POST', '/chat/send/text', 0);
    }
}
