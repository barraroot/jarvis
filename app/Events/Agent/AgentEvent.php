<?php

namespace App\Events\Agent;

use App\Models\AgentExecution;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Shared shape of the `agent.*` realtime events (US-7.4): every event carries
 * the execution identity, the agent slug, the delegation chain position and
 * the execution status. The `progress_note` is only filled by Progress events.
 */
abstract class AgentEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly User $user,
        public readonly AgentExecution $execution,
        public readonly ?string $progressNote = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->user->id);
    }

    abstract public function broadcastAs(): string;

    /**
     * @return array{execution_id: int, agent: string|null, depth: int, parent_id: int|null, status: string|null, progress_note: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'execution_id' => $this->execution->id,
            'agent' => $this->execution->agent?->slug,
            'depth' => $this->execution->depth,
            'parent_id' => $this->execution->parent_agent_execution_id,
            'status' => $this->execution->status?->slug,
            'progress_note' => $this->progressNote,
        ];
    }
}
