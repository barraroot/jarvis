<?php

namespace Tests\Feature;

use App\Events\Whatsapp\SessionStatusChanged;
use App\Exceptions\WhatsappOperationException;
use App\Exceptions\WhatsappSessionStateException;
use App\Exceptions\WhatsappUpstreamUnavailableException;
use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\ConnectorStatus;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\Tool;
use App\Models\User;
use App\Models\WhatsappConsent;
use App\Models\WhatsappMessage;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappScheduleStatus;
use App\Models\WhatsappSession;
use App\Services\WhatsappSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappSessionServiceTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        config(['services.wuzapi.webhook_base_url' => 'https://jarvis.example.com']);
    }

    private function service(): WhatsappSessionService
    {
        return app(WhatsappSessionService::class);
    }

    private function userWithConsent(): User
    {
        $user = User::factory()->create();

        WhatsappConsent::factory()->for($user)->create();

        return $user;
    }

    private function whatsappTool(): Tool
    {
        return Tool::factory()->write()->create(['identifier' => 'whatsapp.send_text']);
    }

    public function test_provision_creates_wuzapi_user_with_webhook_and_hmac_and_audits_without_secrets(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $this->fakeWuzapi();
        $user = $this->userWithConsent();

        [$session, $created] = $this->service()->provision($user);

        $this->assertTrue($created);
        $session->refresh();
        $this->assertSame(1, WhatsappSession::query()->where('user_id', $user->id)->count());
        $this->assertSame(ConnectorStatus::PENDING, $session->statusSlug());
        $this->assertSame('wuzapi-user-1', $session->wuzapi_user_id);
        $this->assertGreaterThanOrEqual(32, strlen((string) $session->webhook_key));
        $this->assertGreaterThanOrEqual(32, strlen((string) $session->hmac_key));

        $this->assertWuzapiCalled('POST', '/admin/users');
        $this->assertWuzapiCalled('POST', '/session/hmac/config');

        Http::assertSent(function (Request $request) use ($session): bool {
            return str_ends_with($request->url(), '/admin/users')
                && $request->header('Authorization')[0] === $this->wuzapiAdminToken
                && $request['token'] === $session->token
                && $request['webhook'] === 'https://jarvis.example.com/api/v1/webhooks/wuzapi/'.$session->webhook_key
                && str_contains($request['events'], 'Message')
                && str_contains($request['events'], 'Connected');
        });
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/session/hmac/config')
            && $request->header('Token')[0] === $session->token
            && $request['hmac_key'] === $session->hmac_key);

        $audits = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_SESSION_PROVISIONED))->get();
        $this->assertCount(1, $audits);
        $serialized = json_encode($audits->first()->properties);
        $this->assertStringNotContainsString((string) $session->token, $serialized);
        $this->assertStringNotContainsString((string) $session->hmac_key, $serialized);
        $this->assertStringNotContainsString((string) $session->webhook_key, $serialized);
        $this->assertSame($session->id, $audits->first()->properties['whatsapp_session_id']);
    }

    public function test_second_provision_returns_existing_session_without_new_wuzapi_user(): void
    {
        $this->fakeWuzapi();
        $user = $this->userWithConsent();

        [$first] = $this->service()->provision($user);
        [$second, $created] = $this->service()->provision($user);

        $this->assertFalse($created);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WhatsappSession::query()->count());
        $this->assertWuzapiCalled('POST', '/admin/users', 1);
    }

    public function test_provision_without_valid_consent_never_calls_wuzapi(): void
    {
        $this->fakeWuzapi();
        $user = User::factory()->create();
        WhatsappConsent::factory()->for($user)->revoked()->create();

        try {
            $this->service()->provision($user);
            $this->fail('Expected consent to be required.');
        } catch (WhatsappOperationException $exception) {
            $this->assertSame(WhatsappOperationException::CONSENT_REQUIRED, $exception->reason);
        }

        $this->assertSame(0, WhatsappSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_upstream_failure_records_error_and_retry_reaches_pending(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $healthy = false;
        $this->fakeWuzapi([
            'POST /admin/users' => function () use (&$healthy) {
                return $healthy
                    ? Http::response(['code' => 201, 'data' => ['id' => 'wuzapi-user-1'], 'success' => true], 201)
                    : Http::response(['code' => 500, 'error' => 'boom token '.$this->wuzapiAdminToken, 'success' => false], 500);
            },
        ]);
        $user = $this->userWithConsent();

        try {
            $this->service()->provision($user);
            $this->fail('Expected the upstream to be unavailable.');
        } catch (WhatsappUpstreamUnavailableException $exception) {
            $this->assertSame(__('whatsapp.session.upstream_unavailable'), $exception->getMessage());
        }

        $session = WhatsappSession::query()->sole();
        $this->assertSame(ConnectorStatus::ERROR, $session->statusSlug());
        $this->assertNull($session->wuzapi_user_id);
        $this->assertNotNull($session->last_error);
        $this->assertStringNotContainsString($this->wuzapiAdminToken, $session->last_error);
        $this->assertStringNotContainsString((string) $session->token, $session->last_error);
        Event::assertDispatched(SessionStatusChanged::class, fn (SessionStatusChanged $event): bool => $event->broadcastWith()['status'] === ConnectorStatus::ERROR);
        $this->assertSame(0, AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_SESSION_PROVISIONED))->count());

        $healthy = true;
        [$retried, $created] = $this->service()->provision($user);

        $this->assertFalse($created);
        $this->assertSame($session->id, $retried->id);
        $this->assertSame(ConnectorStatus::PENDING, $retried->refresh()->statusSlug());
        $this->assertNull($retried->last_error);
        $this->assertSame('wuzapi-user-1', $retried->wuzapi_user_id);
        $this->assertWuzapiCalled('POST', '/admin/users', 2);
        $this->assertSame(1, WhatsappSession::query()->count());
    }

    public function test_retry_with_existing_wuzapi_user_only_redoes_hmac(): void
    {
        $this->fakeWuzapi();
        $session = WhatsappSession::factory()->withConsent()->create([
            'wuzapi_user_id' => 'wuzapi-user-1',
            'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::ERROR),
            'last_error' => 'HTTP 500',
        ]);

        $this->service()->provision($session->user);

        $this->assertSame(ConnectorStatus::PENDING, $session->refresh()->statusSlug());
        $this->assertWuzapiCalled('POST', '/admin/users', 0);
        $this->assertWuzapiCalled('POST', '/session/hmac/config', 1);
    }

    public function test_qr_and_pair_phone_are_returned_for_pending_or_disconnected_session_and_never_persisted(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $this->fakeWuzapi();
        $pending = WhatsappSession::factory()->create();
        $disconnected = WhatsappSession::factory()->disconnected()->create();

        $qr = $this->service()->requestQr($pending);
        $code = $this->service()->pairPhone($disconnected, '5511988887777');

        $this->assertSame('data:image/png;base64,iVBORw0KGgo=', $qr);
        $this->assertSame('ABCD-EFGH', $code);
        $this->assertWuzapiCalled('POST', '/session/connect', 2);
        $this->assertWuzapiCalled('GET', '/session/qr', 1);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/session/pairphone')
            && $request['Phone'] === '5511988887777');

        foreach ([$pending->refresh(), $disconnected->refresh()] as $session) {
            $this->assertStringNotContainsString('iVBORw0KGgo', json_encode($session->getAttributes()));
            $this->assertStringNotContainsString('ABCD-EFGH', json_encode($session->getAttributes()));
        }
        $this->assertSame(0, AuditLog::query()->count());
        Event::assertNotDispatched(SessionStatusChanged::class);
    }

    public function test_qr_is_denied_for_connected_or_suspended_session(): void
    {
        $this->fakeWuzapi();
        $connected = WhatsappSession::factory()->connected()->create();
        $suspended = WhatsappSession::factory()->suspended()->create();

        foreach ([$connected, $suspended] as $session) {
            try {
                $this->service()->requestQr($session);
                $this->fail('Expected the session not to be pairable.');
            } catch (WhatsappSessionStateException) {
            }

            try {
                $this->service()->pairPhone($session, '5511988887777');
                $this->fail('Expected the session not to be pairable.');
            } catch (WhatsappSessionStateException) {
            }
        }

        Http::assertNothingSent();
    }

    public function test_revoke_consent_logs_out_purges_denies_and_pauses(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $this->fakeWuzapi();
        $session = WhatsappSession::factory()->connected()->withConsent()->create(['wuzapi_user_id' => 'wuzapi-user-1']);
        $user = $session->user;
        WhatsappMessage::factory()->count(3)->forSession($session)->create();
        $active = WhatsappSchedule::factory()->forSession($session)->create();
        $paused = WhatsappSchedule::factory()->forSession($session)->paused()->create();
        $whatsappApproval = Approval::factory()->for($user)->create(['tool_id' => $this->whatsappTool()->id]);
        $otherApproval = Approval::factory()->for($user)->create();

        $otherSession = WhatsappSession::factory()->withConsent()->create();
        WhatsappMessage::factory()->forSession($otherSession)->create();

        $this->service()->revokeConsent($user);

        $this->assertWuzapiCalled('POST', '/session/logout', 1);
        $session->refresh();
        $this->assertSame(ConnectorStatus::DISCONNECTED, $session->statusSlug());
        $this->assertNotNull(WhatsappConsent::query()->where('user_id', $user->id)->value('revoked_at'));
        $this->assertSame(0, WhatsappMessage::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, WhatsappMessage::query()->where('user_id', $otherSession->user_id)->count());

        $this->assertSame(ApprovalStatus::DENIED, $whatsappApproval->refresh()->status->slug);
        $this->assertSame(__('whatsapp.approvals.consent_revoked'), $whatsappApproval->decision_reason);
        $this->assertSame(ApprovalStatus::PENDING, $otherApproval->refresh()->status->slug);

        $this->assertSame(WhatsappScheduleStatus::idFor(WhatsappScheduleStatus::PAUSED), $active->refresh()->whatsapp_schedule_status_id);
        $this->assertSame(WhatsappScheduleStatus::idFor(WhatsappScheduleStatus::PAUSED), $paused->refresh()->whatsapp_schedule_status_id);

        $this->assertSame(1, AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_CONSENT_REVOKED))->count());
        Event::assertDispatched(SessionStatusChanged::class, fn (SessionStatusChanged $event): bool => $event->broadcastWith()['status'] === ConnectorStatus::DISCONNECTED);

        $this->service()->revokeConsent($user);

        $this->assertWuzapiCalled('POST', '/session/logout', 1);
        $this->assertSame(1, AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_CONSENT_REVOKED))->count());
    }

    public function test_revoke_consent_completes_locally_when_logout_fails(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $this->fakeWuzapi([
            'POST /session/logout' => Http::response(['code' => 500, 'error' => 'down', 'success' => false], 500),
        ]);
        $session = WhatsappSession::factory()->connected()->withConsent()->create(['wuzapi_user_id' => 'wuzapi-user-1']);
        WhatsappMessage::factory()->forSession($session)->create();

        $this->service()->revokeConsent($session->user);

        $session->refresh();
        $this->assertSame(ConnectorStatus::DISCONNECTED, $session->statusSlug());
        $this->assertStringContainsString('HTTP 500', (string) $session->last_error);
        $this->assertStringNotContainsString((string) $session->token, (string) $session->last_error);
        $this->assertSame(0, WhatsappMessage::query()->count());
        $this->assertFalse(WhatsappConsent::query()->where('user_id', $session->user_id)->sole()->isValid());
    }

    public function test_remove_deletes_upstream_user_and_every_local_row(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $this->fakeWuzapi();
        $session = WhatsappSession::factory()->connected()->withConsent()->create(['wuzapi_user_id' => 'wuzapi-user-1']);
        $user = $session->user;
        WhatsappMessage::factory()->count(2)->forSession($session)->create();
        WhatsappOutboundMessage::factory()->forSession($session)->queued()->create();
        WhatsappSchedule::factory()->forSession($session)->create();
        $approval = Approval::factory()->for($user)->create(['tool_id' => $this->whatsappTool()->id]);

        $this->assertTrue($this->service()->remove($user));

        $this->assertWuzapiCalled('DELETE', '/admin/users/wuzapi-user-1/full', 1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->header('Authorization')[0] === $this->wuzapiAdminToken);
        $this->assertSame(0, WhatsappSession::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, WhatsappMessage::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, WhatsappOutboundMessage::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, WhatsappSchedule::query()->where('user_id', $user->id)->count());
        $this->assertSame(ApprovalStatus::DENIED, $approval->refresh()->status->slug);
        $this->assertSame(__('whatsapp.approvals.session_removed'), $approval->decision_reason);

        $audit = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_SESSION_REMOVED))->sole();
        $this->assertSame('wuzapi-user-1', $audit->properties['wuzapi_user_id']);
        $this->assertStringNotContainsString((string) $session->token, json_encode($audit->properties));
    }

    public function test_remove_keeps_everything_when_upstream_fails(): void
    {
        $this->fakeWuzapi([
            'DELETE /admin/users/wuzapi-user-1/full' => Http::response(['code' => 500, 'error' => 'down', 'success' => false], 500),
        ]);
        $session = WhatsappSession::factory()->create(['wuzapi_user_id' => 'wuzapi-user-1']);

        $this->expectException(WhatsappUpstreamUnavailableException::class);

        try {
            $this->service()->remove($session->user);
        } finally {
            $this->assertTrue(WhatsappSession::query()->whereKey($session->id)->exists());
            $this->assertSame(0, AuditLog::query()->count());
        }
    }

    public function test_suspend_disconnects_notifies_and_audits_and_resume_clears_it(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $this->fakeWuzapi();
        $admin = User::factory()->admin()->create();
        $session = WhatsappSession::factory()->connected()->create(['wuzapi_user_id' => 'wuzapi-user-1']);

        $this->service()->suspend($session, $admin);

        $this->assertWuzapiCalled('POST', '/session/disconnect', 1);
        $session->refresh();
        $this->assertTrue($session->isSuspended());
        Event::assertDispatched(SessionStatusChanged::class, fn (SessionStatusChanged $event): bool => $event->broadcastWith()['suspended'] === true);

        $notification = Notification::query()->where('user_id', $session->user_id)->sole();
        $this->assertSame(NotificationType::idFor(NotificationType::WHATSAPP_SESSION_SUSPENDED), $notification->notification_type_id);

        $suspendAudit = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_SESSION_SUSPENDED))->sole();
        $this->assertSame($admin->id, $suspendAudit->user_id);

        $this->service()->suspend($session, $admin);
        $this->assertWuzapiCalled('POST', '/session/disconnect', 1);

        $this->service()->resume($session, $admin);

        $session->refresh();
        $this->assertFalse($session->isSuspended());
        $this->assertNull($session->last_checked_at);
        $this->assertSame(1, AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_SESSION_RESUMED))->count());
        Event::assertDispatched(SessionStatusChanged::class, fn (SessionStatusChanged $event): bool => $event->broadcastWith()['suspended'] === false);
    }
}
