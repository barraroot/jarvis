<?php

namespace App\Jobs;

use App\Models\Visualization;
use App\Services\VisualizationService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Trailing broadcast of a throttled visualization (RNF-04): an update
 * suppressed by the 500 ms window schedules this job, which transmits the
 * latest persisted state. Unique per visualization until it starts, so a
 * burst of updates yields one trailing event that still reads fresh data.
 */
class BroadcastVisualizationUpdate implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'default';

    /**
     * Delay of the trailing event, matching the throttle window.
     */
    public const int DELAY_MILLISECONDS = 500;

    public int $tries = 1;

    /**
     * The unique lock never outlives a stuck job for long.
     */
    public int $uniqueFor = 10;

    public function __construct(public readonly int $visualizationId)
    {
        $this->onQueue(self::QUEUE);
        $this->delay(now()->addMilliseconds(self::DELAY_MILLISECONDS));
    }

    public function uniqueId(): string
    {
        return (string) $this->visualizationId;
    }

    public function handle(VisualizationService $visualizations): void
    {
        $visualization = Visualization::query()->with('status')->find($this->visualizationId);

        if ($visualization === null) {
            return;
        }

        $visualizations->broadcastNow($visualization);
    }
}
