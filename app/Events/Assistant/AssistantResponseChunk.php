<?php

namespace App\Events\Assistant;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One incremental piece of the streamed assistant reply (workflow 2, step 4).
 */
class AssistantResponseChunk implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly User $user,
        public readonly int $messageId,
        public readonly string $delta,
        public readonly int $index,
        public readonly ?int $conversationId = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->user->id);
    }

    public function broadcastAs(): string
    {
        return 'assistant.response.chunk';
    }

    /**
     * `conversation_id` lets the SPA drop chunks that belong to another open
     * conversation instead of leaking them into the current one (Phase 8).
     *
     * @return array{message_id: int, delta: string, index: int, conversation_id: int|null}
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'delta' => $this->delta,
            'index' => $this->index,
            'conversation_id' => $this->conversationId,
        ];
    }
}
