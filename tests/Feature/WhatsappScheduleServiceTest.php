<?php

namespace Tests\Feature;

use App\Exceptions\WhatsappScheduleStateException;
use App\Models\Approval;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappScheduleStatus;
use App\Models\WhatsappSession;
use App\Services\WhatsappScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappScheduleServiceTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    private WhatsappSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWuzapi();

        $this->setUpSeedsLookups();

        // 2026-11-07 10:00 in America/Sao_Paulo.
        $this->travelTo(CarbonImmutable::parse('2026-11-07 13:00:00', 'UTC'));

        $this->session = WhatsappSession::factory()->connected()->withConsent()->create();
        $this->user = $this->session->user;
    }

    private function service(): WhatsappScheduleService
    {
        return app(WhatsappScheduleService::class);
    }

    private function approval(?CarbonImmutable $decidedAt = null): Approval
    {
        return Approval::factory()->approved()->create([
            'user_id' => $this->user->id,
            'decided_at' => $decidedAt ?? now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{to_jid: string, kind: string, payload: array<string, mixed>, run_at?: string|null, recurrence?: string|null}
     */
    private function data(array $overrides = []): array
    {
        return array_merge([
            'to_jid' => '5511988887777@s.whatsapp.net',
            'kind' => WhatsappSchedule::KIND_TEXT,
            'payload' => ['text' => 'Bom dia!'],
            'run_at' => null,
            'recurrence' => '0 9 * * *',
        ], $overrides);
    }

    public function test_valid_definitions_have_no_errors(): void
    {
        $now = now();

        $this->assertSame([], $this->service()->definitionErrors(null, '0 9 * * *', $now));
        $this->assertSame([], $this->service()->definitionErrors(null, '0 * * * *', $now));
        $this->assertSame([], $this->service()->definitionErrors(null, '30 8 * * 1-5', $now));
        $this->assertSame([], $this->service()->definitionErrors('2026-11-08 09:00', null, $now));
        $this->assertSame([], $this->service()->definitionErrors('2027-02-05 09:00', null, $now), 'within 90 days');
    }

    public function test_invalid_definitions_are_rejected(): void
    {
        $now = now();
        $cases = [
            'past one-off' => ['2026-11-07 09:59', null],
            'now' => ['2026-11-07 10:00', null],
            'beyond 90 days' => ['2027-02-06 10:01', null],
            'unparseable date' => ['not a date', null],
            'invalid cron' => [null, '61 9 * * *'],
            'six fields' => [null, '0 0 9 * * *'],
            'alias' => [null, '@daily'],
            'every 30 minutes' => [null, '*/30 * * * *'],
            'two runs within the hour' => [null, '0,30 9 * * *'],
            'both' => ['2026-11-08 09:00', '0 9 * * *'],
            'neither' => [null, null],
        ];

        foreach ($cases as $label => [$runAt, $recurrence]) {
            $errors = $this->service()->definitionErrors($runAt, $recurrence, $now);

            $this->assertCount(1, $errors, "Expected an error for [{$label}].");
            $this->assertIsString($errors[0]);
        }

        $this->assertSame(
            [__('whatsapp.schedules.cron_too_frequent')],
            $this->service()->definitionErrors(null, '*/30 * * * *', $now),
        );
    }

    public function test_create_daily_cron_runs_at_nine_sao_paulo_which_is_noon_utc(): void
    {
        $approval = $this->approval(CarbonImmutable::parse('2026-11-07 12:30:00', 'UTC'));

        $schedule = $this->service()->create($this->user, $this->session, $approval, $this->data());

        $this->assertSame(WhatsappScheduleStatus::ACTIVE, $schedule->statusSlug());
        $this->assertSame('0 9 * * *', $schedule->recurrence);
        $this->assertNull($schedule->run_at);
        $this->assertSame('2026-11-08 12:00:00', $schedule->next_run_at->utc()->toDateTimeString());
        $this->assertSame('2027-02-05 12:30:00', $schedule->approval_expires_at->utc()->toDateTimeString());
        $this->assertSame($approval->id, $schedule->approval_id);

        $audit = AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_SCHEDULE_CREATED))
            ->sole();

        $this->assertSame($this->user->id, $audit->user_id);
        $this->assertSame($schedule->id, $audit->properties['schedule_id']);
        $this->assertArrayNotHasKey('payload', $audit->properties);
        $this->assertStringNotContainsString('Bom dia!', json_encode($audit->properties));
    }

    public function test_create_one_off_stores_run_at_in_utc(): void
    {
        $schedule = $this->service()->create($this->user, $this->session, $this->approval(), $this->data([
            'run_at' => '2026-11-10 18:30',
            'recurrence' => null,
        ]));

        $this->assertSame('2026-11-10 21:30:00', $schedule->run_at->utc()->toDateTimeString());
        $this->assertSame('2026-11-10 21:30:00', $schedule->next_run_at->utc()->toDateTimeString());
        $this->assertFalse($schedule->isRecurring());
    }

    public function test_approval_without_decision_date_counts_from_now(): void
    {
        $approval = $this->approval();
        $approval->forceFill(['decided_at' => null])->save();

        $schedule = $this->service()->create($this->user, $this->session, $approval, $this->data());

        $this->assertSame('2027-02-05 13:00:00', $schedule->approval_expires_at->utc()->toDateTimeString());
    }

    public function test_create_rejects_invalid_definition(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->service()->create($this->user, $this->session, $this->approval(), $this->data(['recurrence' => '*/30 * * * *']));
        } finally {
            $this->assertSame(0, WhatsappSchedule::query()->count());
        }
    }

    public function test_cancel_active_and_paused_schedules(): void
    {
        $active = WhatsappSchedule::factory()->forSession($this->session)->recurring()->create();
        $paused = WhatsappSchedule::factory()->forSession($this->session)->recurring()->paused()->create();

        $this->service()->cancel($active, $this->user);
        $this->service()->cancel($paused, $this->user);

        foreach ([$active, $paused] as $schedule) {
            $schedule->refresh();
            $this->assertSame(WhatsappScheduleStatus::CANCELLED, $schedule->statusSlug());
            $this->assertNull($schedule->next_run_at);
        }

        $this->assertSame(2, AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_SCHEDULE_CANCELLED))
            ->where('user_id', $this->user->id)
            ->count());
    }

    public function test_cancel_completed_or_cancelled_throws_state_exception(): void
    {
        foreach (['completed', 'cancelled'] as $state) {
            $schedule = WhatsappSchedule::factory()->forSession($this->session)->{$state}()->create();

            try {
                $this->service()->cancel($schedule, $this->user);
                $this->fail("Cancelling a {$state} schedule should fail.");
            } catch (WhatsappScheduleStateException $exception) {
                $this->assertSame(__('whatsapp.schedules.not_cancellable'), $exception->getMessage());
            }

            $this->assertSame($state, $schedule->refresh()->statusSlug());
        }

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_resume_recalculates_from_now_and_keeps_approval_deadline(): void
    {
        $deadline = CarbonImmutable::parse('2027-01-01 12:00:00', 'UTC');
        $schedule = WhatsappSchedule::factory()->forSession($this->session)->recurring()->paused()->create([
            'next_run_at' => CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
            'approval_expires_at' => $deadline,
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-11-20 15:00:00', 'UTC'));

        $resumed = $this->service()->resume($schedule);

        $this->assertSame(WhatsappScheduleStatus::ACTIVE, $resumed->statusSlug());
        $this->assertSame('2026-11-21 12:00:00', $resumed->next_run_at->utc()->toDateTimeString());
        $this->assertTrue($resumed->next_run_at->isFuture());
        $this->assertSame($deadline->toDateTimeString(), $resumed->refresh()->approval_expires_at->utc()->toDateTimeString());
    }

    public function test_resume_one_off_whose_time_passed_completes_it(): void
    {
        $schedule = WhatsappSchedule::factory()->forSession($this->session)->paused()->create([
            'run_at' => now()->subHour(),
            'next_run_at' => now()->subHour(),
        ]);

        $resumed = $this->service()->resume($schedule);

        $this->assertSame(WhatsappScheduleStatus::COMPLETED, $resumed->statusSlug());
        $this->assertNull($resumed->next_run_at);
    }

    public function test_resume_non_paused_throws_state_exception(): void
    {
        $schedule = WhatsappSchedule::factory()->forSession($this->session)->recurring()->create();

        $this->expectException(WhatsappScheduleStateException::class);
        $this->expectExceptionMessage(__('whatsapp.schedules.not_resumable'));

        $this->service()->resume($schedule);
    }

    public function test_next_occurrence_and_occurrences_between(): void
    {
        $schedule = WhatsappSchedule::factory()->forSession($this->session)->recurring('0 9 * * *')->make();

        $this->assertSame(
            '2026-11-08 12:00:00',
            $this->service()->nextOccurrenceAfter($schedule, CarbonImmutable::parse('2026-11-07 12:00:00', 'UTC'))->toDateTimeString(),
        );
        $this->assertSame(
            '2026-11-07 12:00:00',
            $this->service()->nextOccurrenceAfter($schedule, CarbonImmutable::parse('2026-11-07 11:59:30', 'UTC'))->toDateTimeString(),
        );

        $occurrences = $this->service()->occurrencesBetween(
            $schedule,
            CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-07 13:00:00', 'UTC'),
        );

        $this->assertSame(
            ['2026-11-03 12:00:00', '2026-11-04 12:00:00', '2026-11-05 12:00:00', '2026-11-06 12:00:00', '2026-11-07 12:00:00'],
            array_map(fn (CarbonImmutable $moment): string => $moment->toDateTimeString(), $occurrences),
        );

        $oneOff = WhatsappSchedule::factory()->forSession($this->session)->make(['run_at' => CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC')]);

        $this->assertNull($this->service()->nextOccurrenceAfter($oneOff, CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC')));
        $this->assertCount(1, $this->service()->occurrencesBetween($oneOff, now(), CarbonImmutable::parse('2026-11-11', 'UTC')));
        $this->assertSame([], $this->service()->occurrencesBetween($oneOff, now(), CarbonImmutable::parse('2026-11-09', 'UTC')));
    }
}
