<?php

namespace Tests\Feature;

use App\Exceptions\WhatsappOperationException;
use App\Jobs\DeliverWhatsappOutbound;
use App\Models\Approval;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\User;
use App\Models\WhatsappConsent;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundStatus;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappScheduleStatus;
use App\Models\WhatsappSession;
use App\Services\WhatsappOutboundService;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappScheduleDispatchTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private const string CONTACT = '5511988887777@s.whatsapp.net';

    private WhatsappSession $session;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
        $this->fakeWuzapi();

        Queue::fake();
        Sleep::fake();

        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:30:00', WhatsappSchedule::TIMEZONE));

        $this->session = WhatsappSession::factory()->connected()->withConsent()->create();
        $this->user = $this->session->user;

        // Locks live on the Redis lock connection, which `Cache::flush()` does not clear.
        (new UniqueLock(Cache::store()))->release(new DeliverWhatsappOutbound($this->session->id));
        Cache::lock(WhatsappOutboundService::deliveryLockKey($this->session->id))->forceRelease();
        Cache::lock(WhatsappOutboundService::guardLockKey($this->session->id))->forceRelease();
    }

    protected function tearDown(): void
    {
        Queue::releaseUniqueJobLocks();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function schedule(array $attributes = [], ?string $cron = null): WhatsappSchedule
    {
        $factory = WhatsappSchedule::factory()->forSession($this->session);

        if ($cron !== null) {
            $factory = $factory->recurring($cron);
        }

        return $factory->create(array_merge(['to_jid' => self::CONTACT, 'payload' => ['text' => 'Bom dia']], $attributes));
    }

    /**
     * A São Paulo wall-clock time in UTC (Eloquent stores the given offset as is).
     */
    private function local(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, WhatsappSchedule::TIMEZONE)->utc();
    }

    private function dispatchSchedules(): void
    {
        $this->artisan('jarvis:whatsapp:dispatch-schedules')->assertSuccessful();
    }

    private function runDeliveries(): void
    {
        $processed = 0;

        while (($job = Queue::pushed(DeliverWhatsappOutbound::class)->values()->get($processed)) !== null) {
            $processed++;

            if ($job->delay !== null) {
                $this->travelTo($job->delay);
            }

            (new UniqueLock(Cache::store()))->release($job);

            $job->handle(app(WhatsappOutboundService::class));
        }
    }

    /**
     * @return Collection<int, AuditLog>
     */
    private function executedAudits(): Collection
    {
        return AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_SCHEDULE_EXECUTED))->get();
    }

    private function failureNotifications(): int
    {
        return Notification::query()
            ->where('user_id', $this->user->id)
            ->where('notification_type_id', NotificationType::idFor(NotificationType::WHATSAPP_SCHEDULE_RUN_FAILED))
            ->count();
    }

    public function test_command_is_scheduled_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'jarvis:whatsapp:dispatch-schedules'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_due_one_off_occurrence_enqueues_one_send_and_audits_the_result_after_delivery(): void
    {
        $schedule = $this->schedule(['run_at' => $this->local('12:30'), 'next_run_at' => $this->local('12:30')]);

        $this->dispatchSchedules();

        $outbound = WhatsappOutboundMessage::query()->sole();
        $this->assertSame($schedule->id, $outbound->whatsapp_schedule_id);
        $this->assertSame(['text' => 'Bom dia'], $outbound->payload);
        $this->assertSame(0, $outbound->meta['discarded_occurrences']);
        $this->assertCount(0, $this->executedAudits());
        $this->assertNoWuzapiSend();

        $this->runDeliveries();

        $this->assertWuzapiCalled('POST', '/chat/send/text');
        $this->assertSame(WhatsappOutboundStatus::SENT, $outbound->fresh()->status->slug);
        $audits = $this->executedAudits();
        $this->assertCount(1, $audits);
        $this->assertSame('sent', $audits->first()->properties['result']);
        $this->assertSame(0, $audits->first()->properties['discarded']);

        $schedule->refresh()->load('status');
        $this->assertSame(WhatsappScheduleStatus::COMPLETED, $schedule->statusSlug());
        $this->assertNull($schedule->next_run_at);
        $this->assertNotNull($schedule->last_run_at);

        $this->dispatchSchedules();
        $this->assertSame(1, WhatsappOutboundMessage::query()->count());
    }

    public function test_five_overdue_occurrences_send_once_and_record_four_discarded(): void
    {
        $schedule = $this->schedule(['next_run_at' => $this->local('08:00')], cron: '0 * * * *');

        $this->dispatchSchedules();

        $outbound = WhatsappOutboundMessage::query()->sole();
        $this->assertSame(4, $outbound->meta['discarded_occurrences']);

        $schedule->refresh()->load('status');
        $this->assertSame(WhatsappScheduleStatus::ACTIVE, $schedule->statusSlug());
        $this->assertTrue($schedule->next_run_at->equalTo($this->local('13:00')));

        $this->runDeliveries();

        $this->assertWuzapiCalled('POST', '/chat/send/text', 1);
        $audit = $this->executedAudits()->sole();
        $this->assertSame(4, $audit->properties['discarded']);
        $this->assertSame('sent', $audit->properties['result']);
    }

    public function test_not_yet_due_schedule_is_left_alone(): void
    {
        $schedule = $this->schedule(['next_run_at' => $this->local('12:31')], cron: '31 * * * *');

        $this->dispatchSchedules();

        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
        $this->assertTrue($schedule->fresh()->next_run_at->equalTo($this->local('12:31')));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidConsents(): array
    {
        return [
            'new consent version' => [['version' => WhatsappConsent::CURRENT_VERSION - 1]],
            'send scope removed' => [['scopes' => [WhatsappConsent::SCOPE_READ]]],
            'revoked' => [['revoked_at' => '2026-11-10 10:00:00']],
        ];
    }

    /**
     * @param  array<string, mixed>  $consent
     */
    #[DataProvider('invalidConsents')]
    public function test_invalid_consent_blocks_the_occurrence_with_audit_and_notification(array $consent): void
    {
        WhatsappConsent::query()->where('user_id', $this->user->id)->update(
            isset($consent['scopes']) ? ['scopes' => json_encode($consent['scopes'])] : $consent,
        );
        $schedule = $this->schedule(['next_run_at' => $this->local('12:00')], cron: '0 * * * *');

        $this->dispatchSchedules();

        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
        Queue::assertNotPushed(DeliverWhatsappOutbound::class);
        $this->assertNoWuzapiSend();

        $audit = $this->executedAudits()->sole();
        $this->assertSame('blocked', $audit->properties['result']);
        $this->assertSame(WhatsappOperationException::CONSENT_REQUIRED, $audit->properties['reason']);
        $this->assertSame(0, $audit->properties['discarded']);
        $this->assertSame($schedule->id, $audit->properties['schedule_id']);

        $this->assertSame(1, $this->failureNotifications());
        $notification = Notification::query()->sole();
        $this->assertSame(WhatsappOperationException::translate(WhatsappOperationException::CONSENT_REQUIRED), $notification->body);

        $this->assertTrue($schedule->fresh()->next_run_at->equalTo($this->local('13:00')));
    }

    public function test_kill_switch_blocks_the_occurrence(): void
    {
        app(Settings::class)->set('whatsapp.kill_switch', 1);
        $this->schedule(['next_run_at' => $this->local('12:00')], cron: '0 * * * *');

        $this->dispatchSchedules();

        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
        $this->assertSame(WhatsappOperationException::KILL_SWITCH_ACTIVE, $this->executedAudits()->sole()->properties['reason']);
        $this->assertSame(1, $this->failureNotifications());
    }

    public function test_schedule_past_its_approval_window_completes_without_sending_or_new_approval(): void
    {
        $schedule = $this->schedule([
            'next_run_at' => $this->local('12:00'),
            'approval_expires_at' => $this->local('2026-11-10 11:00'),
        ], cron: '0 * * * *');

        $this->dispatchSchedules();

        $schedule->refresh()->load('status');
        $this->assertSame(WhatsappScheduleStatus::COMPLETED, $schedule->statusSlug());
        $this->assertNull($schedule->next_run_at);
        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
        $this->assertSame(0, Approval::query()->count());
        $this->assertCount(0, $this->executedAudits());
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_last_occurrence_before_the_deadline_runs_and_completes_the_schedule(): void
    {
        $schedule = $this->schedule([
            'next_run_at' => $this->local('12:00'),
            'approval_expires_at' => $this->local('2026-11-10 12:45'),
        ], cron: '0 * * * *');

        $this->dispatchSchedules();

        $this->assertSame(1, WhatsappOutboundMessage::query()->count());
        $this->assertSame(WhatsappScheduleStatus::COMPLETED, $schedule->refresh()->load('status')->statusSlug());
        $this->assertSame(0, Approval::query()->count());
    }

    public function test_paused_and_cancelled_schedules_never_enqueue(): void
    {
        $paused = WhatsappSchedule::factory()->forSession($this->session)->recurring('0 * * * *')->paused()
            ->create(['next_run_at' => $this->local('10:00')]);
        WhatsappSchedule::factory()->forSession($this->session)->recurring('0 * * * *')->cancelled()
            ->create(['next_run_at' => $this->local('10:00')]);

        $this->dispatchSchedules();

        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
        $this->assertCount(0, $this->executedAudits());
        $this->assertTrue($paused->fresh()->next_run_at->equalTo($this->local('10:00')));
    }
}
