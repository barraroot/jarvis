<?php

namespace Tests\Feature;

use App\Ai\Agents\MemoryHypothesisExtractor;
use App\Events\Assistant\AssistantResponseCompleted;
use App\Jobs\ExtractMemoryHypotheses;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Conversation;
use App\Models\ExecutionStatus;
use App\Models\Memory;
use App\Models\MemoryStatus;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Embeddings;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ExtractMemoryHypothesesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    private Conversation $conversation;

    private Message $assistantMessage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();

        $provider = AiProvider::factory()->create();
        AiModel::factory()->defaultText()->for($provider, 'provider')->create(['model_identifier' => 'default-chat']);
        AiModel::factory()->defaultEmbedding()->for($provider, 'provider')->create();

        $this->conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        $userMessage = Message::factory()->for($this->conversation, 'conversation')->create(['content' => 'Estou desenvolvendo o projeto JARVIS em Laravel']);

        $this->assistantMessage = Message::factory()
            ->for($this->conversation, 'conversation')
            ->fromAssistant()
            ->create([
                'content' => 'Entendido, vou acompanhar seu progresso.',
                'ai_model_id' => AiModel::query()->where('is_default_text', true)->value('id'),
            ]);

        Embeddings::fake();
    }

    public function test_creates_hypotheses_and_skips_sensitive_ones(): void
    {
        MemoryHypothesisExtractor::fake([
            ['items' => [
                ['content' => 'Está desenvolvendo o projeto JARVIS em Laravel'],
                ['content' => 'O CPF do usuário é 123.456.789-01'],
                ['content' => 'senha: hunter2'],
            ]],
        ]);

        Event::fake();

        (new ExtractMemoryHypotheses($this->assistantMessage))->handle();

        $this->assertSame(1, Memory::query()->count());

        $memory = Memory::query()->firstOrFail();

        $this->assertSame('Está desenvolvendo o projeto JARVIS em Laravel', $memory->content);
        $this->assertSame($this->user->id, $memory->user_id);
        $this->assertSame(MemoryStatus::idFor(MemoryStatus::HYPOTHESIS), $memory->memory_status_id);
        $this->assertNull($memory->confirmed_at);
        $this->assertSame($this->conversation->id, $memory->source_conversation_id);

        $assistant = $this->assistantMessage->fresh();

        $this->assertSame([$memory->id], $assistant->meta['hypotheses']);

        Event::assertDispatched(AssistantResponseCompleted::class, function (AssistantResponseCompleted $event) use ($assistant): bool {
            return $event->message->is($assistant)
                && $event->broadcastWith()['message']['hypotheses'][0]['content'] === 'Está desenvolvendo o projeto JARVIS em Laravel';
        });
    }

    public function test_skips_completed_or_missing_messages(): void
    {
        Event::fake();

        $pending = Message::factory()->for($this->conversation, 'conversation')->fromAssistant()->create([
            'content' => '',
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::PENDING),
        ]);

        MemoryHypothesisExtractor::fake();

        (new ExtractMemoryHypotheses($pending))->handle();

        $this->assertSame(0, Memory::query()->count());
        Event::assertNotDispatched(AssistantResponseCompleted::class);
    }
}
