<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ContextBuilder;
use App\Services\ContextSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ContextBuilderTest extends TestCase
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
    }

    public function test_includes_last_n_messages_in_chronological_order(): void
    {
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        foreach (range(1, 25) as $index) {
            Message::factory()
                ->when($index % 2 === 0, fn ($factory) => $factory->fromAssistant())
                ->for($conversation, 'conversation')
                ->create(['content' => 'Mensagem '.$index]);
        }

        $context = app(ContextBuilder::class)->build($conversation, $this->model);

        // Default N = 20 messages, oldest first: ids 6..25.
        $this->assertCount(20, $context);
        $this->assertSame('Mensagem 6', $context[0]['content']);
        $this->assertSame('Mensagem 25', $context[19]['content']);

        foreach ($context as $index => $message) {
            $expectedRole = ((6 + $index) % 2 === 0) ? 'assistant' : 'user';
            $this->assertSame($expectedRole, $message['role']);
        }
    }

    public function test_trims_oldest_messages_when_over_token_budget(): void
    {
        $model = AiModel::factory()->for($this->model->provider, 'provider')->create(['context_window' => 400]);

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        foreach (range(1, 6) as $index) {
            Message::factory()
                ->for($conversation, 'conversation')
                ->create(['content' => str_repeat('a', 200).'-'.$index]);
        }

        $context = app(ContextBuilder::class)->build($conversation, $model);

        // Budget = 60% of 400 = 240 tokens; each message costs 50 tokens, so the
        // two oldest are dropped and the newest is always kept.
        $this->assertCount(4, $context);
        $this->assertStringEndsWith('-3', $context[0]['content']);
        $this->assertStringEndsWith('-6', $context[3]['content']);
    }

    public function test_excludes_failed_and_streaming_messages(): void
    {
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        Message::factory()->for($conversation, 'conversation')->create(['content' => 'Pergunta do usuário']);
        Message::factory()->failed()->for($conversation, 'conversation')->create(['content' => 'Resposta que falhou']);
        Message::factory()->streaming()->for($conversation, 'conversation')->create(['content' => 'Ainda transmitindo']);
        Message::factory()->fromAssistant()->for($conversation, 'conversation')->create(['content' => 'Resposta concluída']);

        $context = app(ContextBuilder::class)->build($conversation, $this->model);

        $this->assertCount(2, $context);
        $this->assertSame('Pergunta do usuário', $context[0]['content']);
        $this->assertSame('Resposta concluída', $context[1]['content']);
    }

    public function test_reserves_slots_for_context_sources(): void
    {
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);
        Message::factory()->for($conversation, 'conversation')->create(['content' => 'Mensagem da conversa']);

        $source = new class implements ContextSource
        {
            /**
             * @return list<array{role: string, content: string}>
             */
            public function contextMessages(): array
            {
                return [
                    ['role' => 'system', 'content' => 'Instruções do agente'],
                    ['role' => 'system', 'content' => 'Memória relevante'],
                ];
            }
        };

        $context = app(ContextBuilder::class)->build($conversation, $this->model, sources: [$source]);

        $this->assertCount(3, $context);
        $this->assertSame('Instruções do agente', $context[0]['content']);
        $this->assertSame('Memória relevante', $context[1]['content']);
        $this->assertSame('Mensagem da conversa', $context[2]['content']);
    }
}
