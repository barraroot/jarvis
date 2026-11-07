<?php

namespace App\Jobs;

use App\Services\WhatsappOutboundService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Sleep;

/**
 * Serialized delivery of a session's `queued` sends (D4, RNF-05): unique per
 * session until it starts, one attempt only, and the delivery lock inside
 * `WhatsappOutboundService`. The interval is honoured by dispatching a new
 * delayed job — never `release()`, which would count as a second attempt.
 */
class DeliverWhatsappOutbound implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'default';

    public int $tries = 1;

    /**
     * Above the delivery lock, so a lost job never blocks the session for long.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $whatsappSessionId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->whatsappSessionId;
    }

    public function handle(WhatsappOutboundService $outbound): void
    {
        $nextDeliveryAt = $outbound->deliverNext($this->whatsappSessionId);

        // The sync driver ignores delays: wait in place instead of recursing.
        while ($nextDeliveryAt !== null && $this->job instanceof SyncJob) {
            Sleep::until($nextDeliveryAt);

            $nextDeliveryAt = $outbound->deliverNext($this->whatsappSessionId);
        }

        if ($nextDeliveryAt !== null) {
            self::dispatch($this->whatsappSessionId)->delay(self::delayUntil($nextDeliveryAt));
        }
    }

    private static function delayUntil(CarbonImmutable $at): CarbonImmutable
    {
        return $at->isFuture() ? $at : CarbonImmutable::now();
    }
}
