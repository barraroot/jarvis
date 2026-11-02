<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Memory;
use App\Models\MemoryEmbedding;
use App\Models\User;
use App\Services\MemoryRetriever;
use Database\Factories\MemoryEmbeddingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class MemoryRetrieverTest extends TestCase
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
        $this->model = AiModel::factory()->defaultEmbedding()->create();
    }

    public function test_returns_only_confirmed_memories_of_the_user_above_threshold_ordered_by_similarity(): void
    {
        $closest = $this->memoryEmbedding([1.0, 0.1, 0.0], confirmed: true);        // similarity ~0.995
        $close = $this->memoryEmbedding([1.0, 0.3, 0.0], confirmed: true);          // similarity ~0.958
        $below = $this->memoryEmbedding([1.0, 1.0, 0.0], confirmed: true);          // similarity ~0.707 < 0.75
        $this->memoryEmbedding([1.0, 0.05, 0.0], confirmed: false);                  // hypothesis, never retrieved

        Embeddings::fake(fn (): array => [MemoryEmbeddingFactory::fakeVector([1.0, 0.0, 0.0])]);

        $memories = app(MemoryRetriever::class)->relevantFor($this->user, 'consulta');

        $this->assertSame([$closest->memory->id, $close->memory->id], $memories->pluck('id')->all());
        $this->assertNotContains($below->memory->id, $memories->pluck('id')->all());
        $this->assertTrue($memories->every(fn (Memory $memory): bool => $memory->isConfirmed()));
        $this->assertTrue($memories->every(fn (Memory $memory): bool => $memory->last_used_at !== null));
    }

    public function test_never_returns_other_users_memories(): void
    {
        $stranger = User::factory()->create();

        $this->memoryEmbedding([1.0, 0.05, 0.0], confirmed: true, user: $stranger);

        Embeddings::fake(fn (): array => [MemoryEmbeddingFactory::fakeVector([1.0, 0.0, 0.0])]);

        $this->assertTrue(app(MemoryRetriever::class)->relevantFor($this->user, 'consulta')->isEmpty());
    }

    private function memoryEmbedding(array $leading, bool $confirmed, ?User $user = null): MemoryEmbedding
    {
        $memory = Memory::factory()
            ->for($user ?? $this->user)
            ->when($confirmed, fn ($factory) => $factory->confirmed(), fn ($factory) => $factory->hypothesis())
            ->create();

        return MemoryEmbedding::factory()
            ->for($memory)
            ->for($this->model, 'model')
            ->vector($leading)
            ->create();
    }
}
