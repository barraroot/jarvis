<?php

namespace App\Events\Task;

use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `task.updated` on the operator's private channel (US-10.2): every state
 * transition and progress change carries the full task snapshot (status,
 * progress, current step), so the side panel follows the task without polling.
 */
class TaskUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly Task $task) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->task->user_id);
    }

    public function broadcastAs(): string
    {
        return 'task.updated';
    }

    /**
     * @return array{task: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'task' => TaskResource::make($this->task->load(['agent', 'status', 'steps']))->resolve(),
        ];
    }
}
