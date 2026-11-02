<?php

namespace Tests\Feature;

use App\Events\Conversation\ConversationMessageCreated;
use App\Jobs\ProcessUserMessage;
use App\Models\Conversation;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\MessageRole;
use App\Models\MessageSource;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class StoreMessageTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
        $this->conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'last_activity_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($this->user);
    }

    public function test_valid_message_returns_202_and_dispatches_job(): void
    {
        Queue::fake();
        Event::fake();

        $response = $this->postJson(
            '/api/v1/conversations/'.$this->conversation->id.'/messages',
            ['content' => 'Resuma minhas tarefas de hoje', 'source' => 'text'],
        );

        $response->assertAccepted()
            ->assertJsonPath('data.conversation_id', $this->conversation->id)
            ->assertJsonStructure(['data' => ['message_id', 'conversation_id']]);

        $message = Message::query()->firstOrFail();

        $this->assertDatabaseHas('messages', [
            'id' => $response->json('data.message_id'),
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'message_role_id' => MessageRole::idFor(MessageRole::USER),
            'message_source_id' => MessageSource::idFor(MessageSource::TEXT),
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'content' => 'Resuma minhas tarefas de hoje',
        ]);

        // The reply is processed asynchronously on the Redis queue.
        Queue::assertPushed(ProcessUserMessage::class, fn (ProcessUserMessage $job): bool => $job->userMessage->is($message) && $job->connection === 'redis');

        // Sending touches the conversation's activity.
        $this->assertTrue($this->conversation->fresh()->last_activity_at->gt(now()->subMinutes(5)));
    }

    public function test_content_over_8000_chars_is_rejected(): void
    {
        $this->postJson(
            '/api/v1/conversations/'.$this->conversation->id.'/messages',
            ['content' => str_repeat('a', 8001), 'source' => 'text'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);

        $this->assertSame(0, Message::query()->count());
    }

    public function test_61st_message_in_a_minute_is_rate_limited(): void
    {
        Queue::fake();
        Event::fake();

        $endpoint = '/api/v1/conversations/'.$this->conversation->id.'/messages';

        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->postJson($endpoint, ['content' => 'Mensagem '.$attempt, 'source' => 'text'])
                ->assertAccepted();
        }

        $this->postJson($endpoint, ['content' => 'Mensagem 61', 'source' => 'text'])
            ->assertStatus(429);

        $this->assertSame(60, Message::query()->count());
    }

    public function test_message_created_event_is_broadcast(): void
    {
        Queue::fake();
        Event::fake();

        $this->postJson(
            '/api/v1/conversations/'.$this->conversation->id.'/messages',
            ['content' => 'Olá JARVIS', 'source' => 'voice'],
        )->assertAccepted();

        Event::assertDispatched(ConversationMessageCreated::class, function (ConversationMessageCreated $event): bool {
            return $event->broadcastOn()->name === 'private-user.'.$this->user->id
                && $event->broadcastAs() === 'conversation.message.created'
                && $event->broadcastWith()['message']['id'] === Message::query()->value('id')
                && $event->broadcastWith()['message']['source'] === 'voice';
        });
    }

    public function test_cost_limit_exceeded_returns_402(): void
    {
        $this->user->update(['monthly_cost_limit' => 1.00]);

        UsageRecord::factory()->create([
            'user_id' => $this->user->id,
            'estimated_cost' => 2.00,
        ]);

        $this->postJson(
            '/api/v1/conversations/'.$this->conversation->id.'/messages',
            ['content' => 'Mensagem bloqueada', 'source' => 'text'],
        )
            ->assertStatus(402)
            ->assertJsonPath('message', __('assistant.errors.usage_limit_exceeded'));

        $this->assertSame(0, Message::query()->count());
    }
}
