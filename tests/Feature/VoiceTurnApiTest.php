<?php

namespace Tests\Feature;

use App\Jobs\ExtractMemoryHypotheses;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRole;
use App\Models\MessageSource;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CountsQueries;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class VoiceTurnApiTest extends TestCase
{
    use CountsQueries;
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    private VoiceSession $voiceSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        Queue::fake();

        $this->user = User::factory()->create();
        $this->voiceSession = VoiceSession::factory()->create([
            'user_id' => $this->user->id,
            'last_activity_at' => now()->subMinutes(5),
        ]);
        Conversation::query()->whereKey($this->voiceSession->conversation_id)->update(['last_activity_at' => now()->subMinutes(5)]);

        $this->actingAs($this->user);
    }

    public function test_batch_creates_messages_in_order_with_voice_source(): void
    {
        $response = $this->sendTurns([
            ['role' => 'user', 'content' => 'Jarvis, conta uma piada', 'spoken_at' => now()->subSeconds(5)->toIso8601String()],
            ['role' => 'assistant', 'content' => 'Um programador entra no bar [risos]', 'spoken_at' => now()->toIso8601String()],
        ]);

        $response->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.role', 'user')
            ->assertJsonPath('data.0.source', 'voice')
            ->assertJsonPath('data.0.content', 'Jarvis, conta uma piada')
            ->assertJsonPath('data.1.role', 'assistant')
            ->assertJsonPath('data.1.source', 'voice')
            ->assertJsonPath('data.1.conversation_id', $this->voiceSession->conversation_id);

        $messages = Message::query()->orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertSame(['Jarvis, conta uma piada', 'Um programador entra no bar [risos]'], $messages->pluck('content')->all());
        $this->assertSame([MessageRole::idFor(MessageRole::USER), MessageRole::idFor(MessageRole::ASSISTANT)], $messages->pluck('message_role_id')->all());

        foreach ($messages as $message) {
            $this->assertSame(MessageSource::idFor(MessageSource::VOICE), $message->message_source_id);
            $this->assertSame($this->voiceSession->conversation_id, $message->conversation_id);
            $this->assertSame($this->user->id, $message->user_id);
            $this->assertArrayHasKey('spoken_at', $message->meta);
        }

        $this->assertTrue($this->voiceSession->fresh()->last_activity_at->gt(now()->subMinute()));
        $this->assertTrue(Conversation::query()->find($this->voiceSession->conversation_id)->last_activity_at->gt(now()->subMinute()));
    }

    public function test_dispatches_memory_extraction_for_assistant_turns_on_low_queue(): void
    {
        $this->sendTurns([
            ['role' => 'user', 'content' => 'Oi', 'spoken_at' => now()->toIso8601String()],
            ['role' => 'assistant', 'content' => 'Olá!', 'spoken_at' => now()->toIso8601String()],
            ['role' => 'user', 'content' => 'Tudo bem?', 'spoken_at' => now()->toIso8601String()],
            ['role' => 'assistant', 'content' => 'Tudo ótimo.', 'spoken_at' => now()->toIso8601String()],
        ])->assertCreated();

        $assistantIds = Message::query()
            ->where('message_role_id', MessageRole::idFor(MessageRole::ASSISTANT))
            ->pluck('id')
            ->all();

        Queue::assertPushed(ExtractMemoryHypotheses::class, 2);
        Queue::assertPushed(
            ExtractMemoryHypotheses::class,
            fn (ExtractMemoryHypotheses $job): bool => $job->queue === 'low'
                && $job->connection === 'redis'
                && in_array($job->assistantMessage->id, $assistantIds, true),
        );
    }

    public function test_batch_over_20_returns_422(): void
    {
        $turns = array_fill(0, 21, ['role' => 'user', 'content' => 'fala', 'spoken_at' => now()->toIso8601String()]);

        $this->sendTurns($turns)->assertUnprocessable()->assertJsonValidationErrors(['turns']);

        $this->assertSame(0, Message::query()->count());
    }

    public function test_invalid_turns_return_422(): void
    {
        $this->sendTurns([])->assertUnprocessable()->assertJsonValidationErrors(['turns']);

        $this->sendTurns([
            ['role' => 'system', 'content' => '', 'spoken_at' => 'ontem'],
            ['role' => 'user', 'content' => str_repeat('a', 4001), 'spoken_at' => now()->toIso8601String()],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['turns.0.role', 'turns.0.content', 'turns.0.spoken_at', 'turns.1.content']);

        $this->assertSame(0, Message::query()->count());
    }

    public function test_foreign_session_returns_403(): void
    {
        $foreign = VoiceSession::factory()->create();

        $this->postJson("/api/v1/voice/sessions/{$foreign->id}/turns", [
            'turns' => [['role' => 'user', 'content' => 'Oi', 'spoken_at' => now()->toIso8601String()]],
        ])->assertForbidden();

        $this->assertSame(0, Message::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_constant_query_count(): void
    {
        // Warm the lookup and settings caches so both measurements start equal.
        $this->sendTurns($this->turns(2))->assertCreated();

        $this->travel(1)->seconds();
        $two = $this->countQueries(fn () => $this->sendTurns($this->turns(2))->assertCreated());

        $this->travel(1)->seconds();
        $twenty = $this->countQueries(fn () => $this->sendTurns($this->turns(20))->assertCreated());

        $this->assertSame($two, $twenty);
        $this->assertSame(24, Message::query()->count());
    }

    /**
     * @return list<array{role: string, content: string, spoken_at: string}>
     */
    private function turns(int $count): array
    {
        return array_map(fn (int $index): array => [
            'role' => $index % 2 === 0 ? 'user' : 'assistant',
            'content' => "Turno {$index}",
            'spoken_at' => now()->toIso8601String(),
        ], range(0, $count - 1));
    }

    /**
     * @param  array<int, array<string, mixed>>  $turns
     */
    private function sendTurns(array $turns): TestResponse
    {
        return $this->postJson("/api/v1/voice/sessions/{$this->voiceSession->id}/turns", ['turns' => $turns]);
    }
}
