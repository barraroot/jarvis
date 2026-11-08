<?php

namespace Tests\Feature;

use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappScheduleStatus;
use App\Models\WhatsappSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CountsQueries;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappScheduleApiTest extends TestCase
{
    use CountsQueries;
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private const string ENDPOINT = '/api/v1/whatsapp/schedules';

    private const array SCHEDULE_KEYS = [
        'id',
        'to',
        'kind',
        'recurrence',
        'timezone',
        'next_run_at',
        'approval_expires_at',
        'status',
        'created_at',
    ];

    private User $user;

    private WhatsappSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWuzapi();

        $this->setUpSeedsLookups();

        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:30:00', WhatsappSchedule::TIMEZONE));

        $this->session = WhatsappSession::factory()->connected()->withConsent()->create();
        $this->user = $this->session->user;

        $this->actingAs($this->user);
    }

    private function scheduleFor(WhatsappSession $session): WhatsappSchedule
    {
        return WhatsappSchedule::factory()->forSession($session)->create();
    }

    private function auditCount(string $action): int
    {
        return AuditLog::query()->where('audit_action_id', AuditAction::idFor($action))->count();
    }

    public function test_guest_gets_401(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_lists_only_the_owner_schedules_ordered_by_next_run_with_nulls_last(): void
    {
        $later = WhatsappSchedule::factory()->forSession($this->session)->recurring()->create(['next_run_at' => now()->addDays(2)]);
        $sooner = WhatsappSchedule::factory()->forSession($this->session)->create(['next_run_at' => now()->addHour()]);
        $cancelled = WhatsappSchedule::factory()->forSession($this->session)->cancelled()->create();
        $this->scheduleFor(WhatsappSession::factory()->create());

        $response = $this->getJson(self::ENDPOINT)->assertOk();

        $response->assertJsonStructure(['data' => ['*' => self::SCHEDULE_KEYS]]);
        $this->assertSame([$sooner->id, $later->id, $cancelled->id], array_column($response->json('data'), 'id'));

        $first = $response->json('data.0');
        $this->assertSame(self::SCHEDULE_KEYS, array_keys($first));
        $this->assertSame($sooner->to_jid, $first['to']);
        $this->assertSame('text', $first['kind']);
        $this->assertNull($first['recurrence']);
        $this->assertSame('America/Sao_Paulo', $first['timezone']);
        $this->assertSame(WhatsappScheduleStatus::ACTIVE, $first['status']);
        $this->assertTrue(CarbonImmutable::parse($first['next_run_at'])->equalTo($sooner->next_run_at));
        $this->assertSame('0 9 * * *', $response->json('data.1.recurrence'));
        $this->assertNull($response->json('data.2.next_run_at'));
        $this->assertSame(WhatsappScheduleStatus::CANCELLED, $response->json('data.2.status'));
        $this->assertArrayNotHasKey('payload', $first);
    }

    public function test_listing_uses_a_constant_number_of_queries(): void
    {
        $this->scheduleFor($this->session);
        $few = $this->countQueries(fn () => $this->getJson(self::ENDPOINT)->assertOk());

        WhatsappSchedule::factory()->count(5)->forSession($this->session)->create();
        $many = $this->countQueries(fn () => $this->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(6, 'data'));

        $this->assertSame($few, $many);
    }

    public function test_owner_cancels_an_active_schedule(): void
    {
        $schedule = $this->scheduleFor($this->session);

        $this->postJson(self::ENDPOINT."/{$schedule->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.id', $schedule->id)
            ->assertJsonPath('data.status', WhatsappScheduleStatus::CANCELLED)
            ->assertJsonPath('data.next_run_at', null);

        $this->assertSame(WhatsappScheduleStatus::CANCELLED, $schedule->fresh()->load('status')->statusSlug());
        $this->assertSame(1, $this->auditCount(AuditAction::WHATSAPP_SCHEDULE_CANCELLED));
    }

    public function test_owner_cancels_a_paused_schedule(): void
    {
        $schedule = WhatsappSchedule::factory()->forSession($this->session)->paused()->create();

        $this->postJson(self::ENDPOINT."/{$schedule->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', WhatsappScheduleStatus::CANCELLED);
    }

    public function test_cancelling_another_user_schedule_is_forbidden(): void
    {
        $foreign = $this->scheduleFor(WhatsappSession::factory()->create());

        $this->postJson(self::ENDPOINT."/{$foreign->id}/cancel")->assertForbidden();
        $this->postJson(self::ENDPOINT."/{$foreign->id}/resume")->assertForbidden();

        $this->assertSame(WhatsappScheduleStatus::ACTIVE, $foreign->fresh()->load('status')->statusSlug());
        $this->assertSame(0, $this->auditCount(AuditAction::WHATSAPP_SCHEDULE_CANCELLED));
    }

    public function test_unknown_schedule_is_404(): void
    {
        $this->postJson(self::ENDPOINT.'/999999/cancel')->assertNotFound();
    }

    public function test_cancelling_a_completed_schedule_conflicts(): void
    {
        $schedule = WhatsappSchedule::factory()->forSession($this->session)->completed()->create();

        $this->postJson(self::ENDPOINT."/{$schedule->id}/cancel")
            ->assertConflict()
            ->assertJsonPath('message', __('whatsapp.schedules.not_cancellable'));

        $this->assertSame(WhatsappScheduleStatus::COMPLETED, $schedule->fresh()->load('status')->statusSlug());
    }

    public function test_resuming_a_paused_schedule_makes_it_active_with_a_future_next_run(): void
    {
        $expiresAt = now()->addDays(30)->startOfSecond();
        $schedule = WhatsappSchedule::factory()->forSession($this->session)->recurring('0 9 * * *')->paused()->create([
            'next_run_at' => now()->subDays(3),
            'approval_expires_at' => $expiresAt,
        ]);

        $response = $this->postJson(self::ENDPOINT."/{$schedule->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.status', WhatsappScheduleStatus::ACTIVE);

        $nextRunAt = CarbonImmutable::parse($response->json('data.next_run_at'));
        $this->assertTrue($nextRunAt->isFuture());
        $this->assertTrue($nextRunAt->equalTo(CarbonImmutable::parse('2026-11-11 09:00', WhatsappSchedule::TIMEZONE)));
        $this->assertTrue(CarbonImmutable::parse($response->json('data.approval_expires_at'))->equalTo($expiresAt));
    }

    public function test_resuming_a_schedule_that_is_not_paused_conflicts(): void
    {
        $active = $this->scheduleFor($this->session);
        $cancelled = WhatsappSchedule::factory()->forSession($this->session)->cancelled()->create();

        foreach ([$active, $cancelled] as $schedule) {
            $this->postJson(self::ENDPOINT."/{$schedule->id}/resume")
                ->assertConflict()
                ->assertJsonPath('message', __('whatsapp.schedules.not_resumable'));
        }
    }
}
