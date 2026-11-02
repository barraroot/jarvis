<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Memory;
use App\Models\MemoryEmbedding;
use App\Models\User;
use App\Services\MemoryContextSource;
use Database\Factories\MemoryEmbeddingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class MemoryContextSourceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
        AiModel::factory()->defaultEmbedding()->create();
    }

    public function test_only_confirmed_memories_are_injected(): void
    {
        $confirmed = Memory::factory()->confirmed()->for($this->user)->create(['content' => 'Prefere café preto']);
        $hypothesis = Memory::factory()->hypothesis()->for($this->user)->create(['content' => 'Talvez goste de chá']);

        foreach ([$confirmed, $hypothesis] as $memory) {
            MemoryEmbedding::factory()
                ->for($memory)
                ->for(AiModel::query()->firstOrFail(), 'model')
                ->vector([1.0, 0.0, 0.0])
                ->create();
        }

        Embeddings::fake(fn (): array => [MemoryEmbeddingFactory::fakeVector([1.0, 0.0, 0.0])]);

        $messages = app(MemoryContextSource::class, ['user' => $this->user, 'query' => 'café'])->contextMessages();

        $this->assertCount(1, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertStringContainsString('O que você sabe sobre o usuário (fatos confirmados)', $messages[0]['content']);
        $this->assertStringContainsString('Prefere café preto', $messages[0]['content']);
        $this->assertStringNotContainsString('Talvez goste de chá', $messages[0]['content']);
    }

    public function test_returns_nothing_when_no_memories_are_relevant(): void
    {
        Embeddings::fake(fn (): array => [MemoryEmbeddingFactory::fakeVector([1.0, 0.0, 0.0])]);

        $messages = app(MemoryContextSource::class, ['user' => $this->user, 'query' => 'consulta sem memórias'])->contextMessages();

        $this->assertSame([], $messages);
    }
}
