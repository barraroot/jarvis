<?php

namespace App\Events\Connector;

use App\Models\McpServer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `connector.status.changed` (US-9.4): on the owner's private channel for a
 * personal connector, or on every active user's channel for a global one.
 * Never carries the credential.
 */
class StatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly McpServer $server,
        public readonly ?string $previousStatus,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return $this->server->audience()
            ->map(fn ($user): PrivateChannel => new PrivateChannel('user.'.$user->id))
            ->values()
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'connector.status.changed';
    }

    /**
     * @return array{id: int, name: string, scope: string, status: string, previous_status: string|null, last_error: string|null, last_checked_at: string|null, last_synced_at: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->server->id,
            'name' => $this->server->name,
            'scope' => $this->server->isGlobal() ? 'global' : 'personal',
            'status' => $this->server->status->slug,
            'previous_status' => $this->previousStatus,
            'last_error' => $this->server->last_error,
            'last_checked_at' => $this->server->last_checked_at?->toIso8601String(),
            'last_synced_at' => $this->server->last_synced_at?->toIso8601String(),
        ];
    }
}
