<?php

namespace App\Events\Tool;

use App\Events\Tool\Concerns\BuildsToolPayload;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `tool.started` on the operator's private channel (US-8.2).
 */
class ToolStarted implements ShouldBroadcastNow
{
    use BuildsToolPayload, Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly User $user,
        public readonly Tool $tool,
        public readonly ToolExecution $execution,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->user->id);
    }

    public function broadcastAs(): string
    {
        return 'tool.started';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->toolPayload('running');
    }
}
