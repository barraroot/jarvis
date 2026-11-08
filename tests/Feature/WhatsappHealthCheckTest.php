<?php

namespace Tests\Feature;

use App\Events\Whatsapp\SessionStatusChanged;
use App\Models\ConnectorStatus;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\WhatsappConsent;
use App\Models\WhatsappSession;
use App\Support\Settings;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappHealthCheckTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        app(Settings::class)->set('connector.health_check_minutes', 5);
    }

    /**
     * @return list<string> Tokens of the sessions wuzapi was asked about.
     */
    private function checkedTokens(): array
    {
        return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/session/status'))
            ->map(fn (array $pair): string => $pair[0]->header('Token')[0])
            ->values()
            ->all();
    }

    public function test_only_sessions_due_by_the_interval_are_checked(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $this->fakeWuzapi();
        $recent = WhatsappSession::factory()->connected()->withConsent()->create(['last_checked_at' => now()->subMinutes(4)]);
        $due = WhatsappSession::factory()->connected()->withConsent()->create(['last_checked_at' => now()->subMinutes(6)]);
        $neverChecked = WhatsappSession::factory()->withConsent()->create(['last_checked_at' => null]);

        $this->artisan('jarvis:whatsapp:health-check')->assertSuccessful();

        $tokens = $this->checkedTokens();
        $this->assertCount(2, $tokens);
        $this->assertContains($due->token, $tokens);
        $this->assertContains($neverChecked->token, $tokens);
        $this->assertNotContains($recent->token, $tokens);
        $this->assertTrue($due->refresh()->last_checked_at->isAfter(now()->subMinute()));
        $this->assertTrue($recent->refresh()->last_checked_at->isBefore(now()->subMinutes(3)));
    }

    public function test_force_checks_recent_sessions_too(): void
    {
        $this->fakeWuzapi();
        WhatsappSession::factory()->connected()->withConsent()->create(['last_checked_at' => now()->subMinute()]);

        $this->artisan('jarvis:whatsapp:health-check', ['--force' => true])->assertSuccessful();

        $this->assertWuzapiCalled('GET', '/session/status', 1);
    }

    public function test_suspended_unprovisioned_or_without_valid_consent_sessions_are_skipped(): void
    {
        $this->fakeWuzapi();
        WhatsappSession::factory()->connected()->suspended()->withConsent()->create();
        WhatsappSession::factory()->withConsent()->create(['wuzapi_user_id' => null]);
        WhatsappSession::factory()->connected()->create();
        $revoked = WhatsappSession::factory()->connected()->create();
        WhatsappConsent::factory()->for($revoked->user)->revoked()->create();
        $outdated = WhatsappSession::factory()->connected()->create();
        WhatsappConsent::factory()->for($outdated->user)->outdated()->create();

        $this->artisan('jarvis:whatsapp:health-check')->assertSuccessful();

        $this->assertWuzapiCalled('GET', '/session/status', 0);
    }

    public function test_connected_report_transitions_pending_session_and_broadcasts(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $this->fakeWuzapi();
        $session = WhatsappSession::factory()->withConsent()->create();

        $this->artisan('jarvis:whatsapp:health-check')->assertSuccessful();

        $session->refresh();
        $this->assertSame(ConnectorStatus::CONNECTED, $session->statusSlug());
        $this->assertNotNull($session->connected_at);
        $this->assertNotNull($session->last_checked_at);
        Event::assertDispatchedTimes(SessionStatusChanged::class, 1);
        Event::assertDispatched(SessionStatusChanged::class, fn (SessionStatusChanged $event): bool => $event->broadcastWith()['status'] === ConnectorStatus::CONNECTED
            && $event->broadcastWith()['previous_status'] === ConnectorStatus::PENDING
            && $event->broadcastOn()[0]->name === "private-user.{$session->user_id}");
    }

    public function test_status_mapping_keeps_pending_and_detects_disconnection(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $this->fakeWuzapi(['GET /session/status' => ['connected' => false, 'loggedIn' => false]]);
        $neverPaired = WhatsappSession::factory()->withConsent()->create();
        $wasConnected = WhatsappSession::factory()->connected()->withConsent()->create(['last_checked_at' => now()->subMinutes(10)]);

        $this->artisan('jarvis:whatsapp:health-check')->assertSuccessful();

        $this->assertSame(ConnectorStatus::PENDING, $neverPaired->refresh()->statusSlug());
        $this->assertNotNull($neverPaired->last_checked_at);
        $this->assertSame(ConnectorStatus::DISCONNECTED, $wasConnected->refresh()->statusSlug());
        Event::assertDispatchedTimes(SessionStatusChanged::class, 1);
    }

    public function test_upstream_failure_records_error_and_notifies_the_owner(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $this->fakeWuzapi(['GET /session/status' => Http::response(['code' => 500, 'error' => 'internal', 'success' => false], 500)]);
        $session = WhatsappSession::factory()->connected()->withConsent()->create(['last_checked_at' => now()->subMinutes(10)]);

        $this->artisan('jarvis:whatsapp:health-check')->assertSuccessful();

        $session->refresh();
        $this->assertSame(ConnectorStatus::ERROR, $session->statusSlug());
        $this->assertStringContainsString('HTTP 500', (string) $session->last_error);
        $this->assertStringNotContainsString((string) $session->token, (string) $session->last_error);
        $this->assertSame(1, Notification::query()
            ->where('user_id', $session->user_id)
            ->where('notification_type_id', NotificationType::idFor(NotificationType::CONNECTOR_ERROR))
            ->count());
    }

    public function test_resumed_session_is_reconciled_on_next_run(): void
    {
        $this->fakeWuzapi(['GET /session/status' => ['connected' => false, 'loggedIn' => true]]);
        $session = WhatsappSession::factory()->connected()->withConsent()->create(['last_checked_at' => null]);

        $this->artisan('jarvis:whatsapp:health-check')->assertSuccessful();

        $this->assertSame(ConnectorStatus::DISCONNECTED, $session->refresh()->statusSlug());
    }

    public function test_command_is_scheduled_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn (ScheduledEvent $event): bool => str_contains($event->command, 'jarvis:whatsapp:health-check'),
        );

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
