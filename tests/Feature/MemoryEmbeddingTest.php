<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Memory;
use App\Models\MemoryEmbedding;
use App\Models\User;
use Database\Factories\MemoryEmbeddingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pgvector\Laravel\Distance;
use Pgvector\Laravel\Vector;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class MemoryEmbeddingTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_nearest_neighbors_query_orders_by_cosine_distance(): void
    {
        $user = User::factory()->create();
        $model = AiModel::factory()->defaultEmbedding()->create();

        $close = $this->embeddingFor($user, $model, [1.0, 0.1, 0.0]);
        $far = $this->embeddingFor($user, $model, [0.0, 0.0, 1.0]);
        $medium = $this->embeddingFor($user, $model, [1.0, 1.0, 0.0]);

        $query = MemoryEmbeddingFactory::fakeVector([1.0, 0.0, 0.0]);

        $neighbors = MemoryEmbedding::query()
            ->nearestNeighbors('embedding', $query, Distance::Cosine)
            ->get();

        $this->assertSame([$close->id, $medium->id, $far->id], $neighbors->modelKeys());
        $this->assertLessThan($neighbors[1]->neighbor_distance, $neighbors[0]->neighbor_distance);
        $this->assertLessThan($neighbors[2]->neighbor_distance, $neighbors[1]->neighbor_distance);
        $this->assertEqualsWithDelta(1.0, $neighbors[2]->neighbor_distance, 0.0001);
    }

    public function test_embedding_round_trips_as_vector_and_belongs_to_memory(): void
    {
        $embedding = MemoryEmbedding::factory()->create();

        $fresh = $embedding->fresh();

        $this->assertInstanceOf(Vector::class, $fresh->embedding);
        $this->assertCount(MemoryEmbedding::DIMENSIONS, $fresh->embedding->toArray());
        $this->assertTrue($fresh->memory->embedding->is($embedding));
        $this->assertTrue($fresh->model->memoryEmbeddings->contains($embedding));
    }

    public function test_memory_scopes_and_confirmation_state(): void
    {
        $confirmed = Memory::factory()->confirmed()->create();
        $hypothesis = Memory::factory()->hypothesis()->create();

        $this->assertTrue($confirmed->isConfirmed());
        $this->assertFalse($hypothesis->isConfirmed());
        $this->assertSame([$confirmed->id], Memory::confirmed()->pluck('id')->all());
        $this->assertSame([$hypothesis->id], Memory::hypotheses()->pluck('id')->all());
        $this->assertNull($hypothesis->confirmed_at);
    }

    /**
     * @param  array<int, float>  $leading
     */
    private function embeddingFor(User $user, AiModel $model, array $leading): MemoryEmbedding
    {
        return MemoryEmbedding::factory()
            ->for(Memory::factory()->for($user))
            ->for($model, 'model')
            ->vector($leading)
            ->create();
    }
}
