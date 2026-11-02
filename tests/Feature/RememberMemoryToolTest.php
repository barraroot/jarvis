<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\MemoryStatus;
use App\Models\Message;
use App\Models\ToolType;
use App\Models\User;
use App\Services\ProviderRegistry;
use App\Tools\RememberMemoryTool;
use Database\Factories\MemoryEmbeddingFactory;
use Database\Seeders\RememberMemoryToolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Tools\Request;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

use function Laravel\Ai\agent;

class RememberMemoryToolTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    private AiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
        $this->provider = AiProvider::factory()->create();

        AiModel::factory()->defaultEmbedding()->for($this->provider, 'provider')->create();
    }

    public function test_tool_call_creates_confirmed_memory_with_source(): void
    {
        $this->seed(RememberMemoryToolSeeder::class);

        $this->assertDatabaseHas('tools', [
            'identifier' => 'memory.remember',
            'tool_type_id' => ToolType::idFor(ToolType::INTERNAL),
            'is_write' => false,
            'is_sensitive' => false,
            'handler_class' => RememberMemoryTool::class,
        ]);

        $model = AiModel::factory()->for($this->provider, 'provider')->create(['model_identifier' => 'chat-model']);

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);
        $userMessage = Message::factory()->for($conversation, 'conversation')->create(['content' => '/lembrar gosto de café preto']);

        Embeddings::fake(fn (): array => [MemoryEmbeddingFactory::fakeVector([1.0, 0.0, 0.0])]);

        $steps = 0;

        Ai::fakeAgent(AnonymousAgent::class, function (string $prompt, mixed $attachments, mixed $provider, string $chatModel) use (&$steps): mixed {
            $steps++;

            if ($steps === 1) {
                return new ToolCall('call_1', 'memory.remember', [
                    'content' => 'O usuário gosta de café preto',
                    'category' => 'preference',
                ]);
            }

            return new TextResponse('Pronto, salvei essa preferência.', new TextUsage(10, 5), new Meta($provider->name(), $chatModel));
        });

        $tool = app(RememberMemoryTool::class, ['user' => $this->user, 'source' => $userMessage]);

        app(ProviderRegistry::class)->register();

        $stream = agent(
            instructions: 'Você é o JARVIS.',
            tools: [$tool],
        )->stream('Lembre que gosto de café preto', provider: $this->provider->slug, model: $model->model_identifier);

        foreach ($stream as $event) {
            // Consume the stream so the tool call executes.
        }

        $this->assertSame(2, $steps);
        $this->assertSame('Pronto, salvei essa preferência.', $stream->text);

        $memory = Memory::query()->firstOrFail();

        $this->assertSame($this->user->id, $memory->user_id);
        $this->assertSame('O usuário gosta de café preto', $memory->content);
        $this->assertTrue($memory->isConfirmed());
        $this->assertNotNull($memory->confirmed_at);
        $this->assertSame($conversation->id, $memory->source_conversation_id);
        $this->assertSame($userMessage->id, $memory->source_message_id);
        $this->assertTrue($memory->embedding()->exists());
    }

    public function test_duplicate_reports_update_instead_of_new(): void
    {
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);
        $userMessage = Message::factory()->for($conversation, 'conversation')->create();

        Embeddings::fake(fn (): array => [MemoryEmbeddingFactory::fakeVector([1.0, 0.0, 0.0])]);

        $tool = app(RememberMemoryTool::class, ['user' => $this->user, 'source' => $userMessage]);

        $first = $tool->handle(new Request([
            'content' => 'Prefere reuniões pela manhã',
            'category' => 'preference',
        ]));

        $second = $tool->handle(new Request([
            'content' => 'Prefere reuniões pela manhã',
            'category' => 'preference',
        ]));

        $this->assertSame('Memória salva: Prefere reuniões pela manhã', (string) $first);
        $this->assertSame('Memória atualizada: Prefere reuniões pela manhã', (string) $second);
        $this->assertSame(1, Memory::query()->count());
        $this->assertSame(MemoryStatus::idFor(MemoryStatus::CONFIRMED), Memory::query()->value('memory_status_id'));
    }
}
