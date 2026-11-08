<?php

namespace App\Console\Commands;

use App\Exceptions\WhatsappOperationException;
use App\Models\AuditAction;
use App\Models\NotificationType;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappScheduleStatus;
use App\Services\NotificationService;
use App\Services\WhatsappOutboundService;
use App\Services\WhatsappScheduleService;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Dispatches due WhatsApp schedule occurrences (RF-31). Each `active`
 * schedule whose `next_run_at` has passed enqueues at most one send through
 * `WhatsappOutboundService` (RF-32: the other overdue occurrences are
 * discarded and recorded), which re-evaluates consent, session and brakes
 * (RF-10, RF-13, RF-17, RF-26). A block at dispatch is audited as
 * `whatsapp_schedule_executed` and notified (D9, D10, RF-34); a queued send
 * is audited by the delivery. Past the 90-day approval window the schedule
 * becomes `completed` (D14) — no new approval is ever requested.
 */
class DispatchWhatsappSchedules extends Command
{
    protected $signature = 'jarvis:whatsapp:dispatch-schedules';

    protected $description = 'Enqueue due WhatsApp schedule occurrences';

    public function handle(
        WhatsappScheduleService $schedules,
        WhatsappOutboundService $outbound,
        NotificationService $notifications,
        AuditLogger $audit,
    ): int {
        $now = CarbonImmutable::now();

        $dueSchedules = WhatsappSchedule::query()
            ->where('whatsapp_schedule_status_id', WhatsappScheduleStatus::idFor(WhatsappScheduleStatus::ACTIVE))
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->with(['status', 'session', 'approval', 'user.whatsappConsent'])
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->get();

        $enqueued = 0;

        foreach ($dueSchedules as $schedule) {
            if ($now->greaterThan($schedule->approval_expires_at)) {
                $this->markCompleted($schedule);

                continue;
            }

            $discarded = max(0, count($schedules->occurrencesBetween($schedule, $schedule->next_run_at, $now)) - 1);

            // Advance before enqueueing: a crash in between skips one
            // occurrence instead of sending it twice.
            $this->advance($schedule, $schedules, $now);

            try {
                $outbound->enqueue(
                    $schedule->user,
                    $schedule->kind,
                    $schedule->to_jid,
                    $schedule->payload ?? [],
                    $schedule->approval,
                    $schedule,
                    ['discarded_occurrences' => $discarded],
                );

                $enqueued++;
            } catch (WhatsappOperationException $exception) {
                $this->recordBlocked($schedule, $exception->reason, $discarded, $notifications, $audit);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->info("Dispatched {$enqueued} of {$dueSchedules->count()} due WhatsApp schedules.");

        return self::SUCCESS;
    }

    /**
     * Next occurrence after now; a one-off schedule, or one whose next
     * occurrence falls past the approval window, becomes `completed`.
     */
    private function advance(WhatsappSchedule $schedule, WhatsappScheduleService $schedules, CarbonImmutable $now): void
    {
        $next = $schedules->nextOccurrenceAfter($schedule, $now);

        if ($next === null || $next->greaterThan($schedule->approval_expires_at)) {
            $this->markCompleted($schedule, $now);

            return;
        }

        $schedule->forceFill(['next_run_at' => $next, 'last_run_at' => $now])->save();
    }

    private function markCompleted(WhatsappSchedule $schedule, ?CarbonImmutable $lastRunAt = null): void
    {
        $schedule->forceFill([
            'whatsapp_schedule_status_id' => WhatsappScheduleStatus::idFor(WhatsappScheduleStatus::COMPLETED),
            'next_run_at' => null,
            'last_run_at' => $lastRunAt ?? $schedule->last_run_at,
        ])->save();
    }

    /**
     * The occurrence never reached the queue: its single result audit and
     * the owner notification come from here (D9, D10, RF-34).
     */
    private function recordBlocked(
        WhatsappSchedule $schedule,
        string $reason,
        int $discarded,
        NotificationService $notifications,
        AuditLogger $audit,
    ): void {
        $audit->log(AuditAction::WHATSAPP_SCHEDULE_EXECUTED, $schedule->user, $schedule, [
            'schedule_id' => $schedule->id,
            'outbound_id' => null,
            'result' => 'blocked',
            'reason' => $reason,
            'discarded' => $discarded,
        ]);

        $notifications->notify(
            $schedule->user,
            NotificationType::WHATSAPP_SCHEDULE_RUN_FAILED,
            __('whatsapp.notifications.schedule_run_failed'),
            WhatsappOperationException::translate($reason),
            ['whatsapp_schedule_id' => $schedule->id, 'reason' => $reason],
        );
    }
}
