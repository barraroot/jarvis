<?php

namespace App\Events\Assistant;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The assistant reply is complete (or failed with a friendly error): the final
 * persisted message is broadcast so the SPA replaces the streaming bubble.
 */
class AssistantResponseCompleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly User $user,
        public readonly Message $message,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->user->id);
    }

    public function broadcastAs(): string
    {
        return 'assistant.response.completed';
    }

    /**
     * The conversation title rides along so drawers update live (Phase 8).
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message' => MessageResource::make($this->message)->resolve(),
            'conversation' => [
                'id' => $this->message->conversation_id,
                'title' => $this->message->conversation?->title,
            ],
        ];
    }
}
