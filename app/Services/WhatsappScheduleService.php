<?php

namespace App\Services;

use App\Exceptions\WhatsappScheduleStateException;
use App\Models\Approval;
use App\Models\AuditAction;
use App\Models\User;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappScheduleStatus;
use App\Models\WhatsappSession;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use InvalidArgumentException;
use Throwable;

/**
 * One-off and recurring WhatsApp schedules (RF-30, RF-33, RF-43, D13, D14).
 * Dates and 5-field cron expressions are interpreted in `America/Sao_Paulo`
 * (C-02) and stored in UTC. One approval covers every occurrence for at most
 * 90 days from the decision (C-03); resuming never extends that deadline.
 */
class WhatsappScheduleService
{
    public const int APPROVAL_DAYS = 90;

    public const int MIN_PERIOD_SECONDS = 3600;

    /**
     * Upcoming occurrences inspected to enforce the minimum period (D13).
     */
    public const int PERIOD_CHECK_OCCURRENCES = 200;

    /**
     * Safety bound of `occurrencesBetween()` (an hourly cron yields ~2,200 in 90 days).
     */
    public const int MAX_OCCURRENCES = 5000;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * pt-BR errors of a schedule definition; empty when valid. Exactly one of
     * a future one-off `run_at` (≤ 90 days ahead) or a recurrence (5-field
     * cron whose consecutive occurrences are ≥ 1 h apart) is required.
     *
     * @return list<string>
     */
    public function definitionErrors(?string $runAt, ?string $recurrence, CarbonInterface $now): array
    {
        $runAt = blank($runAt) ? null : $runAt;
        $recurrence = blank($recurrence) ? null : $recurrence;

        if (($runAt === null) === ($recurrence === null)) {
            return [__('whatsapp.schedules.exactly_one')];
        }

        return $runAt !== null
            ? $this->runAtErrors($runAt, $now)
            : $this->recurrenceErrors($recurrence, $now);
    }

    /**
     * Persist an approved schedule as `active` (RF-30).
     *
     * @param  array{to_jid: string, kind: string, payload: array<string, mixed>, run_at?: string|null, recurrence?: string|null}  $data
     *
     * @throws InvalidArgumentException when the definition is invalid (callers validate first)
     */
    public function create(User $user, WhatsappSession $session, Approval $approval, array $data): WhatsappSchedule
    {
        $runAt = $data['run_at'] ?? null;
        $recurrence = isset($data['recurrence']) ? self::normalizeCron($data['recurrence']) : null;
        $errors = $this->definitionErrors($runAt, $recurrence, now());

        if ($errors !== []) {
            throw new InvalidArgumentException($errors[0]);
        }

        $schedule = new WhatsappSchedule([
            'user_id' => $user->id,
            'whatsapp_session_id' => $session->id,
            'approval_id' => $approval->id,
            'whatsapp_schedule_status_id' => WhatsappScheduleStatus::idFor(WhatsappScheduleStatus::ACTIVE),
            'to_jid' => $data['to_jid'],
            'kind' => $data['kind'],
            'payload' => $data['payload'],
            'run_at' => $recurrence === null ? self::parseLocal($runAt)->utc() : null,
            'recurrence' => $recurrence,
            'approval_expires_at' => CarbonImmutable::parse($approval->decided_at ?? now())->addDays(self::APPROVAL_DAYS),
        ]);

        $schedule->next_run_at = $this->nextOccurrenceAfter($schedule, now());
        $schedule->save();

        $this->audit->log(AuditAction::WHATSAPP_SCHEDULE_CREATED, $user, $schedule, [
            'schedule_id' => $schedule->id,
            'session_id' => $session->id,
            'approval_id' => $approval->id,
            'kind' => $schedule->kind,
            'recurrence' => $recurrence,
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'approval_expires_at' => $schedule->approval_expires_at->toIso8601String(),
        ]);

        return $schedule->load('status');
    }

    /**
     * Cancel an `active` or `paused` schedule (RF-33).
     *
     * @throws WhatsappScheduleStateException
     */
    public function cancel(WhatsappSchedule $schedule, User $user): WhatsappSchedule
    {
        $schedule->loadMissing('status');

        if (! in_array($schedule->statusSlug(), [WhatsappScheduleStatus::ACTIVE, WhatsappScheduleStatus::PAUSED], true)) {
            throw WhatsappScheduleStateException::notCancellable();
        }

        $previous = $schedule->statusSlug();

        $schedule->forceFill([
            'whatsapp_schedule_status_id' => WhatsappScheduleStatus::idFor(WhatsappScheduleStatus::CANCELLED),
            'next_run_at' => null,
        ])->save();

        $this->audit->log(AuditAction::WHATSAPP_SCHEDULE_CANCELLED, $user, $schedule, [
            'schedule_id' => $schedule->id,
            'previous_status' => $previous,
        ]);

        return $schedule->load('status');
    }

    /**
     * Resume a `paused` schedule from now on (RF-43): occurrences due during
     * the pause are skipped and `approval_expires_at` is kept (C-08). With no
     * occurrence left before the deadline, the schedule becomes `completed`.
     *
     * @throws WhatsappScheduleStateException
     */
    public function resume(WhatsappSchedule $schedule): WhatsappSchedule
    {
        $schedule->loadMissing('status');

        if ($schedule->statusSlug() !== WhatsappScheduleStatus::PAUSED) {
            throw WhatsappScheduleStateException::notResumable();
        }

        $next = $this->nextOccurrenceAfter($schedule, now());
        $hasNext = $next !== null && $next->lessThanOrEqualTo($schedule->approval_expires_at);

        $schedule->forceFill([
            'whatsapp_schedule_status_id' => WhatsappScheduleStatus::idFor($hasNext ? WhatsappScheduleStatus::ACTIVE : WhatsappScheduleStatus::COMPLETED),
            'next_run_at' => $hasNext ? $next : null,
        ])->save();

        return $schedule->load('status');
    }

    /**
     * First occurrence strictly after `$after`, in UTC; `null` when a one-off
     * schedule's time has passed.
     */
    public function nextOccurrenceAfter(WhatsappSchedule $schedule, CarbonInterface $after): ?CarbonImmutable
    {
        if (! $schedule->isRecurring()) {
            $runAt = $schedule->run_at === null ? null : CarbonImmutable::parse($schedule->run_at)->utc();

            return $runAt !== null && $runAt->greaterThan($after) ? $runAt : null;
        }

        $next = (new CronExpression($schedule->recurrence))
            ->getNextRunDate(self::local($after), 0, false, WhatsappSchedule::TIMEZONE);

        return CarbonImmutable::instance($next)->utc();
    }

    /**
     * Occurrences `t` with `$from ≤ t ≤ $to`, in UTC, oldest first (RF-32).
     *
     * @return list<CarbonImmutable>
     */
    public function occurrencesBetween(WhatsappSchedule $schedule, CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $schedule->isRecurring()) {
            $runAt = $schedule->run_at === null ? null : CarbonImmutable::parse($schedule->run_at)->utc();

            return $runAt !== null && $runAt->betweenIncluded($from, $to) ? [$runAt] : [];
        }

        $cron = new CronExpression($schedule->recurrence);
        $occurrences = [];
        $cursor = self::local($from);
        $allowCurrent = true;

        while (count($occurrences) < self::MAX_OCCURRENCES) {
            $next = CarbonImmutable::instance($cron->getNextRunDate($cursor, 0, $allowCurrent, WhatsappSchedule::TIMEZONE));

            if ($next->greaterThan($to)) {
                break;
            }

            $occurrences[] = $next->utc();
            $cursor = $next;
            $allowCurrent = false;
        }

        return $occurrences;
    }

    /**
     * Collapse whitespace so `0  9 * * *` is stored as `0 9 * * *`.
     */
    public static function normalizeCron(string $expression): string
    {
        return implode(' ', preg_split('/\s+/', trim($expression)) ?: []);
    }

    /**
     * @return list<string>
     */
    private function runAtErrors(string $runAt, CarbonInterface $now): array
    {
        try {
            $moment = self::parseLocal($runAt);
        } catch (Throwable) {
            return [__('whatsapp.schedules.run_at_invalid')];
        }

        if ($moment->lessThanOrEqualTo($now)) {
            return [__('whatsapp.schedules.run_at_past')];
        }

        if ($moment->greaterThan(CarbonImmutable::parse($now)->addDays(self::APPROVAL_DAYS))) {
            return [__('whatsapp.schedules.run_at_too_far', ['days' => self::APPROVAL_DAYS])];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function recurrenceErrors(string $recurrence, CarbonInterface $now): array
    {
        $expression = self::normalizeCron($recurrence);

        if (count(explode(' ', $expression)) !== 5 || ! CronExpression::isValidExpression($expression)) {
            return [__('whatsapp.schedules.cron_invalid')];
        }

        $previous = null;

        foreach ((new CronExpression($expression))->getMultipleRunDates(self::PERIOD_CHECK_OCCURRENCES, self::local($now), false, false, WhatsappSchedule::TIMEZONE) as $occurrence) {
            if ($previous !== null && $occurrence->getTimestamp() - $previous->getTimestamp() < self::MIN_PERIOD_SECONDS) {
                return [__('whatsapp.schedules.cron_too_frequent')];
            }

            $previous = $occurrence;
        }

        return [];
    }

    /**
     * A date/time without an offset is read in `America/Sao_Paulo`.
     */
    private static function parseLocal(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, WhatsappSchedule::TIMEZONE);
    }

    private static function local(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(WhatsappSchedule::TIMEZONE);
    }
}
