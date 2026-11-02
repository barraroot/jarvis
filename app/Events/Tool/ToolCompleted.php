<?php

namespace App\Events\Tool;

use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `tool.completed` on the operator's private channel (US-8.2).
 */
class ToolCompleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

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
        return 'tool.completed';
    }

    /**
     * @return array{tool: string, execution_id: int, status: string}
     */
    public function broadcastWith(): array
    {
        return [
            'tool' => $this->tool->identifier,
            'execution_id' => $this->execution->id,
            'status' => 'completed',
        ];
    }
}
