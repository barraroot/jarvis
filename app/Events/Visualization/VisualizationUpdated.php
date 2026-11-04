<?php

namespace App\Events\Visualization;

use App\Http\Resources\VisualizationResource;
use App\Models\Visualization;
use App\Services\VisualizationService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `visualization.updated` on the owner's private channel (CT-05): the full
 * visualization snapshot every time a section changes, the task finishes or
 * the operator closes the panel. Throttled by {@see VisualizationService}.
 */
class VisualizationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly Visualization $visualization) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->visualization->user_id);
    }

    public function broadcastAs(): string
    {
        return 'visualization.updated';
    }

    /**
     * @return array{visualization: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'visualization' => VisualizationResource::make($this->visualization->loadMissing('status'))->resolve(),
        ];
    }
}
