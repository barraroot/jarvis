<?php

namespace App\Events\Conversation;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The conversation title changed (auto-generated or otherwise) — the SPA
 * updates the drawer live (Phase 8).
 */
class ConversationTitleChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly User $user,
        public readonly Conversation $conversation,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->user->id);
    }

    public function broadcastAs(): string
    {
        return 'conversation.title.changed';
    }

    /**
     * @return array{conversation: array{id: int, title: string}}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation' => [
                'id' => $this->conversation->id,
                'title' => $this->conversation->title,
            ],
        ];
    }
}
