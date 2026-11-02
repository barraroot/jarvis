<?php

namespace App\Events\Conversation;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user message was persisted and queued for processing (workflow 2, step 2).
 */
class ConversationMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly Message $message) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->message->user_id);
    }

    public function broadcastAs(): string
    {
        return 'conversation.message.created';
    }

    /**
     * Payloads use API resources; internal metadata and prompts never leave the backend.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['message' => MessageResource::make($this->message)->resolve()];
    }
}
