<?php

namespace Tests\Feature;

use App\Events\Assistant\AssistantResponseChunk;
use App\Events\Assistant\AssistantResponseCompleted;
use App\Events\Assistant\AssistantStateChanged;
use App\Events\Assistant\AssistantThinking;
use App\Events\Conversation\ConversationMessageCreated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AssistantEventsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
    }

    public function test_events_broadcast_on_owner_private_channel_with_expected_names(): void
    {
        Event::fake();

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);
        $message = Message::factory()->for($conversation, 'conversation')->create([
            'meta' => ['routing' => 'direct_answer', 'system_prompt' => 'never leaked'],
        ]);

        event(new ConversationMessageCreated($message));
        event(new AssistantThinking($this->user, $message->id));
        event(new AssistantStateChanged($this->user, AssistantStateChanged::THINKING, $conversation->id));
        event(new AssistantStateChanged($this->user, AssistantStateChanged::IDLE));
        event(new AssistantResponseChunk($this->user, $message->id, 'Olá', 0));
        event(new AssistantResponseCompleted($this->user, $message));

        $expectedChannel = 'private-user.'.$this->user->id;

        Event::assertDispatched(ConversationMessageCreated::class, fn (ConversationMessageCreated $event): bool => $event->broadcastOn()->name === $expectedChannel
            && $event->broadcastAs() === 'conversation.message.created');

        Event::assertDispatched(AssistantThinking::class, fn (AssistantThinking $event): bool => $event->broadcastOn()->name === $expectedChannel
            && $event->broadcastAs() === 'assistant.thinking'
            && $event->broadcastWith() === ['message_id' => $message->id]);

        Event::assertDispatched(AssistantStateChanged::class, fn (AssistantStateChanged $event): bool => $event->broadcastOn()->name === $expectedChannel
            && $event->broadcastAs() === 'assistant.state.changed'
            && $event->broadcastWith() === ['state' => AssistantStateChanged::THINKING, 'conversation_id' => $conversation->id]);

        Event::assertDispatched(AssistantStateChanged::class, fn (AssistantStateChanged $event): bool => $event->broadcastWith() === ['state' => AssistantStateChanged::IDLE, 'conversation_id' => null]);

        Event::assertDispatched(AssistantResponseChunk::class, fn (AssistantResponseChunk $event): bool => $event->broadcastOn()->name === $expectedChannel
            && $event->broadcastAs() === 'assistant.response.chunk'
            && $event->broadcastWith() === ['message_id' => $message->id, 'delta' => 'Olá', 'index' => 0]);

        Event::assertDispatched(AssistantResponseCompleted::class, function (AssistantResponseCompleted $event) use ($expectedChannel, $message): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()->name === $expectedChannel
                && $event->broadcastAs() === 'assistant.response.completed'
                && $payload['message']['id'] === $message->id
                // Internal metadata and system prompts never leave the backend.
                && ! array_key_exists('meta', $payload['message'])
                && str_contains(json_encode($payload), 'never leaked') === false;
        });

        // Events never target another operator's channel.
        $otherUser = User::factory()->create();

        Event::assertDispatched(AssistantResponseChunk::class, fn (AssistantResponseChunk $event): bool => $event->broadcastOn()->name !== 'private-user.'.$otherUser->id);
    }
}
