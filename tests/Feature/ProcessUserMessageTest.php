<?php

namespace Tests\Feature;

use App\Events\Assistant\AssistantResponseChunk;
use App\Events\Assistant\AssistantResponseCompleted;
use App\Events\Assistant\AssistantStateChanged;
use App\Events\Assistant\AssistantThinking;
use App\Jobs\ExtractMemoryHypotheses;
use App\Jobs\GenerateConversationTitle;
use App\Jobs\ProcessUserMessage;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Conversation;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\MessageRole;
use App\Models\ResultType;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ProcessUserMessageTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();

        $provider = AiProvider::factory()->create();
        $this->model = AiModel::factory()->for($provider, 'provider')->create();

        // Memory retrieval embeds the incoming message; no stored memories exist.
        AiModel::factory()->defaultEmbedding()->for($provider, 'provider')->create();
        Embeddings::fake();
    }

    public function test_streams_chunks_and_persists_completed_message(): void
    {
        Queue::fake();

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'ai_model_id' => $this->model->id,
            'title' => Conversation::PROVISIONAL_TITLE,
        ]);

        $userMessage = Message::factory()->for($conversation, 'conversation')->create(['content' => 'Resuma minhas tarefas']);

        Ai::fakeAgent(AnonymousAgent::class, fn (string $prompt, mixed $attachments, mixed $provider, string $model): TextResponse => new TextResponse('Olá mundo', new TextUsage(20, 10), new Meta($provider->name(), $model)));

        $order = [];
        $firstChunkStatus = null;
        $completedMessage = null;

        config(['broadcasting.default' => 'null']);

        Event::listen(AssistantStateChanged::class, function (AssistantStateChanged $event) use (&$order): void {
            $order[] = 'state:'.$event->state;
        });
        Event::listen(AssistantThinking::class, function () use (&$order): void {
            $order[] = 'thinking';
        });
        Event::listen(AssistantResponseChunk::class, function (AssistantResponseChunk $event) use (&$order, &$firstChunkStatus): void {
            $order[] = 'chunk:'.$event->index;

            if ($event->index === 0) {
                $firstChunkStatus = Message::query()->findOrFail($event->messageId)->status->slug;
            }
        });
        Event::listen(AssistantResponseCompleted::class, function (AssistantResponseCompleted $event) use (&$order, &$completedMessage): void {
            $order[] = 'completed';
            $completedMessage = $event->message;
        });

        (new ProcessUserMessage($userMessage))->handle();

        $this->assertSame([
            'state:THINKING',
            'thinking',
            'chunk:0',
            'chunk:1',
            'completed',
            'state:IDLE',
        ], $order);

        $this->assertSame('running', $firstChunkStatus);

        $assistant = Message::query()->where('message_role_id', MessageRole::idFor(MessageRole::ASSISTANT))->firstOrFail();

        $this->assertTrue($completedMessage->is($assistant));
        $this->assertSame('Olá mundo', $assistant->content);
        $this->assertSame('text', $assistant->payload['type']);
        $this->assertSame('Olá mundo', $assistant->payload['text']);
        $this->assertSame(ResultType::idFor(ResultType::TEXT), $assistant->result_type_id);
        $this->assertSame($this->model->id, $assistant->ai_model_id);
        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::COMPLETED), $assistant->execution_status_id);

        // The first successful reply queues the auto-title job for the provisional title.
        Queue::assertPushed(GenerateConversationTitle::class, fn (GenerateConversationTitle $job): bool => $job->conversation->is($conversation));

        // Hypothesis extraction follows every completed reply on the low queue.
        Queue::assertPushed(ExtractMemoryHypotheses::class, fn (ExtractMemoryHypotheses $job): bool => $job->assistantMessage->is($assistant) && $job->queue === 'low');

        // The reply bumps the conversation activity.
        $this->assertTrue($conversation->fresh()->last_activity_at->gte($userMessage->created_at));
    }

    public function test_provider_failure_marks_message_failed_and_emits_error_state(): void
    {
        Queue::fake();

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'ai_model_id' => $this->model->id,
        ]);

        $userMessage = Message::factory()->for($conversation, 'conversation')->create(['content' => 'Pergunta que falhará']);

        Ai::fakeAgent(AnonymousAgent::class, fn (): never => throw new RuntimeException('Provider exploded.'));

        $order = [];
        $completedMessage = null;

        config(['broadcasting.default' => 'null']);

        Event::listen(AssistantStateChanged::class, function (AssistantStateChanged $event) use (&$order): void {
            $order[] = 'state:'.$event->state;
        });
        Event::listen(AssistantThinking::class, function () use (&$order): void {
            $order[] = 'thinking';
        });
        Event::listen(AssistantResponseChunk::class, function () use (&$order): void {
            $order[] = 'chunk';
        });
        Event::listen(AssistantResponseCompleted::class, function (AssistantResponseCompleted $event) use (&$order, &$completedMessage): void {
            $order[] = 'completed';
            $completedMessage = $event->message;
        });

        (new ProcessUserMessage($userMessage))->handle();

        $assistant = Message::query()->where('message_role_id', MessageRole::idFor(MessageRole::ASSISTANT))->firstOrFail();

        $this->assertSame([
            'state:THINKING',
            'thinking',
            'state:ERROR',
            'state:IDLE',
            'completed',
        ], $order);

        $this->assertTrue($completedMessage->is($assistant));
        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::FAILED), $assistant->execution_status_id);
        $this->assertSame(['error' => 'all_providers_failed', 'exception' => 'AllProvidersFailedException'], $assistant->meta);

        // The operator sees a friendly i18n message, never a stack trace or provider details.
        $this->assertSame(__('assistant.errors.all_providers_failed'), $assistant->content);
        $this->assertStringNotContainsString('RuntimeException', $assistant->content);
        $this->assertStringNotContainsString('Provider exploded', $assistant->content);

        Queue::assertNothingPushed();
    }

    public function test_usage_record_is_created_for_reply(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'ai_model_id' => $this->model->id,
        ]);

        $userMessage = Message::factory()->for($conversation, 'conversation')->create(['content' => 'Pergunta']);

        Ai::fakeAgent(AnonymousAgent::class, fn (string $prompt, mixed $attachments, mixed $provider, string $model): TextResponse => new TextResponse('Resposta', new TextUsage(60, 30), new Meta($provider->name(), $model)));

        Event::fake();

        (new ProcessUserMessage($userMessage))->handle();

        $assistant = Message::query()->where('message_role_id', MessageRole::idFor(MessageRole::ASSISTANT))->firstOrFail();

        $this->assertDatabaseHas('usage_records', [
            'user_id' => $this->user->id,
            'ai_model_id' => $this->model->id,
            'message_id' => $assistant->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'input_tokens' => 60,
            'output_tokens' => 30,
        ]);

        // One record for the memory-retrieval embedding, one for the reply itself.
        $this->assertSame(2, UsageRecord::query()->count());
    }
}
