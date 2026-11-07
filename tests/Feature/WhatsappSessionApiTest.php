<?php

namespace Tests\Feature;

use App\Events\Whatsapp\SessionStatusChanged;
use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\ConnectorStatus;
use App\Models\Tool;
use App\Models\User;
use App\Models\WhatsappConsent;
use App\Models\WhatsappMessage;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundRecipient;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappSession;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappSessionApiTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private const string ENDPOINT = '/api/v1/whatsapp/session';

    private const array SESSION_KEYS = [
        'id',
        'status',
        'suspended',
        'phone_masked',
        'last_error',
        'last_checked_at',
        'connected_at',
        'consent',
        'current_consent_version',
        'usage_today',
    ];

    private User $user;

    /**
     * When true, `POST /admin/users` answers 500 with the admin token in the body.
     */
    private bool $adminFails = false;

    /**
     * When true, the QR and the full user removal answer 500.
     */
    private bool $sessionCallsFail = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        config(['services.wuzapi.webhook_base_url' => 'https://jarvis.example.com']);

        $this->fakeWuzapi([
            'POST /admin/users' => fn (): mixed => $this->adminFails
                ? Http::response(['code' => 500, 'error' => 'db down '.$this->wuzapiAdminToken, 'success' => false], 500)
                : Http::response(['code' => 201, 'data' => ['id' => 'wuzapi-user-1'], 'success' => true], 201),
            'GET /session/qr' => fn (): mixed => $this->sessionCallsFail
                ? Http::response(['code' => 500, 'error' => 'boom', 'success' => false], 500)
                : Http::response(['code' => 200, 'data' => ['QRCode' => 'data:image/png;base64,iVBORw0KGgo='], 'success' => true]),
            'DELETE /admin/users/wuzapi-user-1/full' => fn (): mixed => $this->sessionCallsFail
                ? Http::response(['code' => 500, 'error' => 'boom', 'success' => false], 500)
                : Http::response(['code' => 200, 'data' => ['Details' => 'User deleted successfully'], 'success' => true]),
        ]);

        Event::fake([SessionStatusChanged::class]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function grantConsent(): WhatsappConsent
    {
        return WhatsappConsent::factory()->for($this->user)->create();
    }

    private function ownSession(string $state = 'pending'): WhatsappSession
    {
        $factory = WhatsappSession::factory()->withConsent();

        $factory = match ($state) {
            'connected' => $factory->connected(),
            'disconnected' => $factory->disconnected(),
            'suspended' => $factory->disconnected()->suspended(),
            default => $factory,
        };

        return $factory->create(['user_id' => $this->user->id, 'wuzapi_user_id' => 'wuzapi-user-1']);
    }

    private function audits(string $action): int
    {
        return AuditLog::query()->where('audit_action_id', AuditAction::idFor($action))->count();
    }

    private function assertNoSecrets(TestResponse $response, WhatsappSession $session): void
    {
        $content = (string) $response->getContent();

        foreach ([$session->token, $session->hmac_key, $session->webhook_key, $session->webhook_key_hash, $this->wuzapiAdminToken] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $content);
        }
    }

    public function test_show_without_session_returns_null(): void
    {
        $this->getJson(self::ENDPOINT)->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_show_returns_contract_keys_consent_and_usage_without_secrets(): void
    {
        $session = $this->ownSession('connected');
        app(Settings::class)->set('whatsapp.daily_send_cap', 150);

        foreach (['sent', 'queued', 'failed'] as $status) {
            $outbound = WhatsappOutboundMessage::factory()->forSession($session)->{$status}()->create();
            WhatsappOutboundRecipient::query()->create([
                'whatsapp_outbound_message_id' => $outbound->id,
                'whatsapp_session_id' => $session->id,
                'jid' => $outbound->to_jid,
                'is_non_contact' => false,
                'created_at' => now(),
            ]);
        }

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.status', ConnectorStatus::CONNECTED)
            ->assertJsonPath('data.suspended', false)
            ->assertJsonPath('data.phone_masked', $session->phone_masked)
            ->assertJsonPath('data.consent.version', WhatsappConsent::CURRENT_VERSION)
            ->assertJsonPath('data.consent.scopes', WhatsappConsent::SCOPES)
            ->assertJsonPath('data.consent.valid', true)
            ->assertJsonPath('data.current_consent_version', WhatsappConsent::CURRENT_VERSION)
            ->assertJsonPath('data.usage_today', ['sends' => 2, 'cap' => 150]);

        $this->assertSame(self::SESSION_KEYS, array_keys($response->json('data')));
        $this->assertSame(['version', 'scopes', 'valid'], array_keys($response->json('data.consent')));
        $this->assertNoSecrets($response, $session);
    }

    public function test_outdated_consent_is_reported_invalid(): void
    {
        $session = WhatsappSession::factory()->create(['user_id' => $this->user->id]);
        WhatsappConsent::factory()->for($this->user)->outdated()->create();

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.consent.version', WhatsappConsent::CURRENT_VERSION - 1)
            ->assertJsonPath('data.consent.valid', false)
            ->assertJsonPath('data.current_consent_version', WhatsappConsent::CURRENT_VERSION);
    }

    public function test_show_never_exposes_another_users_session(): void
    {
        WhatsappSession::factory()->connected()->withConsent()->create();

        $this->getJson(self::ENDPOINT)->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_store_provisions_once_with_webhook_and_hmac_and_audits_without_secrets(): void
    {
        $this->grantConsent();

        $response = $this->postJson(self::ENDPOINT);

        $response->assertCreated()->assertJsonPath('data.status', ConnectorStatus::PENDING);
        $this->assertSame(self::SESSION_KEYS, array_keys($response->json('data')));

        $session = WhatsappSession::query()->where('user_id', $this->user->id)->sole();
        $this->assertSame($response->json('data.id'), $session->id);
        $this->assertNoSecrets($response, $session);

        $this->assertWuzapiCalled('POST', '/admin/users', 1);
        $this->assertWuzapiCalled('POST', '/session/hmac/config', 1);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/admin/users')
            && str_starts_with((string) $request['webhook'], 'https://jarvis.example.com/api/v1/webhooks/wuzapi/'));

        $audit = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_SESSION_PROVISIONED))->sole();
        $properties = (string) json_encode($audit->properties);
        foreach ([$session->token, $session->hmac_key, $session->webhook_key] as $secret) {
            $this->assertStringNotContainsString($secret, $properties);
        }

        $second = $this->postJson(self::ENDPOINT);

        $second->assertOk()->assertJsonPath('data.id', $session->id);
        $this->assertSame(1, WhatsappSession::query()->count());
        $this->assertWuzapiCalled('POST', '/admin/users', 1);
    }

    public function test_store_without_valid_consent_is_422_on_consent_without_wuzapi_call(): void
    {
        $this->postJson(self::ENDPOINT)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('consent')
            ->assertJsonPath('errors.consent.0', __('whatsapp.consent.required'));

        WhatsappConsent::factory()->for($this->user)->revoked()->create();

        $this->postJson(self::ENDPOINT)->assertUnprocessable()->assertJsonValidationErrors('consent');

        $this->assertSame(0, WhatsappSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_store_upstream_failure_is_502_with_error_status_then_retry_recovers(): void
    {
        $this->grantConsent();
        $this->adminFails = true;

        $response = $this->postJson(self::ENDPOINT);

        $response->assertStatus(502)->assertJsonPath('message', __('whatsapp.session.upstream_unavailable'));
        $this->assertStringNotContainsString($this->wuzapiAdminToken, (string) $response->getContent());

        $session = WhatsappSession::query()->with('status')->where('user_id', $this->user->id)->sole();
        $this->assertSame(ConnectorStatus::ERROR, $session->statusSlug());
        $this->assertStringNotContainsString($this->wuzapiAdminToken, (string) $session->last_error);
        $this->assertStringNotContainsString($session->token, (string) $session->last_error);
        Event::assertDispatched(SessionStatusChanged::class, fn (SessionStatusChanged $event): bool => $event->broadcastWith()['status'] === ConnectorStatus::ERROR);

        $this->adminFails = false;

        $this->postJson(self::ENDPOINT)->assertOk()->assertJsonPath('data.status', ConnectorStatus::PENDING);
        $this->assertSame(1, WhatsappSession::query()->count());
    }

    public function test_qr_for_pending_and_disconnected_sessions_without_caching(): void
    {
        $session = $this->ownSession();

        $response = $this->postJson(self::ENDPOINT.'/qr');

        $response->assertOk()->assertExactJson(['data' => ['qr' => 'data:image/png;base64,iVBORw0KGgo=']]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertWuzapiCalled('POST', '/session/connect', 1);
        $this->assertSame(0, AuditLog::query()->count());

        $session->forceFill(['connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::DISCONNECTED)])->save();

        $this->postJson(self::ENDPOINT.'/qr')->assertOk()->assertJsonStructure(['data' => ['qr']]);
    }

    public function test_pair_phone_returns_code_without_caching(): void
    {
        $this->ownSession('disconnected');

        $response = $this->postJson(self::ENDPOINT.'/pair-phone', ['phone' => '5511988887777']);

        $response->assertOk()->assertExactJson(['data' => ['code' => 'ABCD-EFGH']]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/session/pairphone')
            && $request['Phone'] === '5511988887777');
    }

    public function test_pair_phone_rejects_invalid_phone(): void
    {
        $this->ownSession();

        foreach (['+5511988887777', '123', '5511-98888-7777', str_repeat('1', 16)] as $phone) {
            $this->postJson(self::ENDPOINT.'/pair-phone', ['phone' => $phone])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('phone');
        }

        $this->postJson(self::ENDPOINT.'/pair-phone')->assertUnprocessable()->assertJsonValidationErrors('phone');
        Http::assertNothingSent();
    }

    public function test_pairing_connected_or_suspended_session_is_409(): void
    {
        $session = $this->ownSession('connected');

        $this->postJson(self::ENDPOINT.'/qr')->assertStatus(409)->assertJsonPath('message', __('whatsapp.session.not_pairable'));
        $this->postJson(self::ENDPOINT.'/pair-phone', ['phone' => '5511988887777'])->assertStatus(409);

        $session->forceFill([
            'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::DISCONNECTED),
            'suspended_at' => now(),
        ])->save();

        $this->postJson(self::ENDPOINT.'/qr')->assertStatus(409);
        $this->postJson(self::ENDPOINT.'/pair-phone', ['phone' => '5511988887777'])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_pairing_without_session_is_404(): void
    {
        WhatsappSession::factory()->withConsent()->create();

        $this->postJson(self::ENDPOINT.'/qr')->assertNotFound()->assertJsonPath('message', __('whatsapp.session.not_found'));
        $this->postJson(self::ENDPOINT.'/pair-phone', ['phone' => '5511988887777'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_pairing_upstream_failure_is_502(): void
    {
        $session = $this->ownSession();
        $this->sessionCallsFail = true;

        $this->postJson(self::ENDPOINT.'/qr')
            ->assertStatus(502)
            ->assertJsonPath('message', __('whatsapp.session.upstream_unavailable'));

        $this->assertSame(ConnectorStatus::PENDING, $session->refresh()->statusSlug());
    }

    public function test_destroy_removes_upstream_and_local_data_and_audits(): void
    {
        $session = $this->ownSession('connected');
        WhatsappMessage::factory()->count(2)->forSession($session)->create();
        WhatsappOutboundMessage::factory()->forSession($session)->queued()->create();
        WhatsappSchedule::factory()->forSession($session)->create();
        $approval = Approval::factory()->for($this->user)->create([
            'tool_id' => Tool::factory()->write()->create(['identifier' => 'whatsapp.send.text'])->id,
        ]);

        $this->deleteJson(self::ENDPOINT)->assertOk()->assertExactJson(['data' => ['deleted' => true]]);

        $this->assertWuzapiCalled('DELETE', '/admin/users/wuzapi-user-1/full', 1);
        $this->assertSame(0, WhatsappSession::query()->count());
        $this->assertSame(0, WhatsappMessage::query()->count());
        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
        $this->assertSame(0, WhatsappSchedule::query()->count());
        $this->assertSame(ApprovalStatus::DENIED, $approval->refresh()->status->slug);
        $this->assertSame(1, $this->audits(AuditAction::WHATSAPP_SESSION_REMOVED));

        $this->getJson(self::ENDPOINT)->assertExactJson(['data' => null]);
    }

    public function test_destroy_without_session_is_404(): void
    {
        $other = WhatsappSession::factory()->withConsent()->create();

        $this->deleteJson(self::ENDPOINT)->assertNotFound();

        $this->assertModelExists($other);
        Http::assertNothingSent();
    }

    public function test_destroy_upstream_failure_is_502_and_keeps_the_session(): void
    {
        $session = $this->ownSession('connected');
        $this->sessionCallsFail = true;

        $this->deleteJson(self::ENDPOINT)
            ->assertStatus(502)
            ->assertJsonPath('message', __('whatsapp.session.upstream_unavailable'));

        $this->assertModelExists($session);
        $this->assertSame(0, $this->audits(AuditAction::WHATSAPP_SESSION_REMOVED));
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->postJson(self::ENDPOINT)->assertUnauthorized();
        $this->deleteJson(self::ENDPOINT)->assertUnauthorized();
        $this->postJson(self::ENDPOINT.'/qr')->assertUnauthorized();
        $this->postJson(self::ENDPOINT.'/pair-phone', ['phone' => '5511988887777'])->assertUnauthorized();
    }
}
