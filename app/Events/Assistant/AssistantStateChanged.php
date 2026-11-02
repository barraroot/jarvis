<?php

namespace App\Events\Assistant;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Assistant state transitions (IDLE, THINKING, …) broadcast to the operator
 * so the JarvisCore reflects them (project-description §4.6).
 */
class AssistantStateChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const string IDLE = 'IDLE';

    public const string LISTENING = 'LISTENING';

    public const string THINKING = 'THINKING';

    public const string SPEAKING = 'SPEAKING';

    public const string EXECUTING = 'EXECUTING';

    public const string SLEEPING = 'SLEEPING';

    public const string ERROR = 'ERROR';

    public const string DISCONNECTED = 'DISCONNECTED';

    public function __construct(
        public readonly User $user,
        public readonly string $state,
        public readonly ?int $conversationId = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->user->id);
    }

    public function broadcastAs(): string
    {
        return 'assistant.state.changed';
    }

    /**
     * @return array{state: string, conversation_id: int|null}
     */
    public function broadcastWith(): array
    {
        return [
            'state' => $this->state,
            'conversation_id' => $this->conversationId,
        ];
    }
}
