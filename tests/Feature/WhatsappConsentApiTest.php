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
use App\Models\WhatsappSchedule;
use App\Models\WhatsappScheduleStatus;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappConsentApiTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private const string ENDPOINT = '/api/v1/whatsapp/consent';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
        $this->fakeWuzapi();
        Event::fake([SessionStatusChanged::class]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function audits(string $action): int
    {
        return AuditLog::query()->where('audit_action_id', AuditAction::idFor($action))->count();
    }

    public function test_grant_records_version_scopes_and_timestamp_and_audits(): void
    {
        $response = $this->postJson(self::ENDPOINT, [
            'version' => WhatsappConsent::CURRENT_VERSION,
            'scopes' => ['envio', 'leitura'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.version', WhatsappConsent::CURRENT_VERSION)
            ->assertJsonPath('data.scopes', ['leitura', 'envio'])
            ->assertJsonPath('data.valid', true);
        $this->assertSame(['version', 'scopes', 'consented_at', 'valid'], array_keys($response->json('data')));
        $this->assertNotNull($response->json('data.consented_at'));

        $consent = WhatsappConsent::query()->where('user_id', $this->user->id)->sole();
        $this->assertSame(['leitura', 'envio'], $consent->scopes);
        $this->assertSame(WhatsappConsent::CURRENT_VERSION, $consent->version);
        $this->assertNotNull($consent->consented_at);
        $this->assertSame(1, $this->audits(AuditAction::WHATSAPP_CONSENT_GRANTED));
        Http::assertNothingSent();
    }

    public function test_grant_replaces_previous_scopes(): void
    {
        WhatsappConsent::factory()->for($this->user)->scopes(WhatsappConsent::SCOPES)->create();

        $this->postJson(self::ENDPOINT, ['version' => WhatsappConsent::CURRENT_VERSION, 'scopes' => ['leitura']])
            ->assertOk()
            ->assertJsonPath('data.scopes', ['leitura']);

        $this->assertSame(['leitura'], WhatsappConsent::query()->where('user_id', $this->user->id)->value('scopes'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidGrants(): array
    {
        return [
            'version other than current' => [['version' => WhatsappConsent::CURRENT_VERSION + 1, 'scopes' => ['leitura']], 'version'],
            'missing version' => [['scopes' => ['leitura']], 'version'],
            'unknown scope' => [['version' => WhatsappConsent::CURRENT_VERSION, 'scopes' => ['leitura', 'admin']], 'scopes.1'],
            'repeated scope' => [['version' => WhatsappConsent::CURRENT_VERSION, 'scopes' => ['leitura', 'leitura']], 'scopes.0'],
            'empty scopes' => [['version' => WhatsappConsent::CURRENT_VERSION, 'scopes' => []], 'scopes'],
            'too many scopes' => [['version' => WhatsappConsent::CURRENT_VERSION, 'scopes' => ['leitura', 'envio', 'grupos', 'midia', 'midia']], 'scopes'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidGrants')]
    public function test_invalid_grant_is_rejected_with_422(array $body, string $field): void
    {
        $this->postJson(self::ENDPOINT, $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame(0, WhatsappConsent::query()->count());
        $this->assertSame(0, $this->audits(AuditAction::WHATSAPP_CONSENT_GRANTED));
    }

    public function test_revoke_applies_effects_and_repeating_returns_identical_body(): void
    {
        $session = WhatsappSession::factory()->connected()->withConsent()->create([
            'user_id' => $this->user->id,
            'wuzapi_user_id' => 'wuzapi-user-1',
        ]);
        WhatsappMessage::factory()->count(2)->forSession($session)->create();
        $schedule = WhatsappSchedule::factory()->forSession($session)->create();
        $approval = Approval::factory()->for($this->user)->create([
            'tool_id' => Tool::factory()->write()->create(['identifier' => 'whatsapp.send.text'])->id,
        ]);

        $first = $this->deleteJson(self::ENDPOINT);

        $first->assertOk()->assertExactJson(['data' => ['revoked' => true]]);
        $this->assertWuzapiCalled('POST', '/session/logout', 1);
        $this->assertSame(ConnectorStatus::DISCONNECTED, $session->refresh()->statusSlug());
        $this->assertSame(0, WhatsappMessage::query()->where('user_id', $this->user->id)->count());
        $this->assertSame(ApprovalStatus::DENIED, $approval->refresh()->status->slug);
        $this->assertSame(WhatsappScheduleStatus::idFor(WhatsappScheduleStatus::PAUSED), $schedule->refresh()->whatsapp_schedule_status_id);
        $this->assertSame(1, $this->audits(AuditAction::WHATSAPP_CONSENT_REVOKED));
        Event::assertDispatched(SessionStatusChanged::class);

        $second = $this->deleteJson(self::ENDPOINT);

        $second->assertOk();
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertWuzapiCalled('POST', '/session/logout', 1);
        $this->assertSame(1, $this->audits(AuditAction::WHATSAPP_CONSENT_REVOKED));
    }

    public function test_revoke_without_consent_or_session_is_ok(): void
    {
        $this->deleteJson(self::ENDPOINT)->assertOk()->assertExactJson(['data' => ['revoked' => true]]);

        Http::assertNothingSent();
    }

    public function test_after_revoking_a_new_grant_allows_pairing_again(): void
    {
        WhatsappSession::factory()->connected()->withConsent()->create([
            'user_id' => $this->user->id,
            'wuzapi_user_id' => 'wuzapi-user-1',
        ]);

        $this->deleteJson(self::ENDPOINT)->assertOk();
        $this->getJson('/api/v1/whatsapp/session')->assertJsonPath('data.consent.valid', false);

        $this->postJson(self::ENDPOINT, ['version' => WhatsappConsent::CURRENT_VERSION, 'scopes' => ['leitura']])->assertOk();

        $this->postJson('/api/v1/whatsapp/session/qr')
            ->assertOk()
            ->assertJsonPath('data.qr', 'data:image/png;base64,iVBORw0KGgo=');
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson(self::ENDPOINT, ['version' => 1, 'scopes' => ['leitura']])->assertUnauthorized();
        $this->deleteJson(self::ENDPOINT)->assertUnauthorized();
    }
}
