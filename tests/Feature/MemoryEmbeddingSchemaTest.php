<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemoryEmbeddingSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $memoryId;

    private int $modelId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $statusId = $this->insertLookup('memory_statuses', 'confirmed');
        $categoryId = $this->insertLookup('memory_categories', 'preference');
        $driverId = $this->insertLookup('ai_provider_drivers', 'openai');

        $providerId = DB::table('ai_providers')->insertGetId([
            'ai_provider_driver_id' => $driverId,
            'name' => 'OpenAI',
            'slug' => 'openai',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->modelId = DB::table('ai_models')->insertGetId([
            'ai_provider_id' => $providerId,
            'name' => 'text-embedding-3-small',
            'model_identifier' => 'text-embedding-3-small',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->memoryId = DB::table('memories')->insertGetId([
            'user_id' => $user->id,
            'memory_status_id' => $statusId,
            'memory_category_id' => $categoryId,
            'content' => 'Prefers concise answers.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_embedding_column_accepts_1536_dimensions_and_rejects_other_sizes(): void
    {
        $this->insertEmbedding($this->memoryId, 1536);

        $this->assertDatabaseHas('memory_embeddings', ['memory_id' => $this->memoryId]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/expected 1536 dimensions/');

        DB::transaction(function (): void {
            DB::table('memory_embeddings')->where('memory_id', $this->memoryId)->delete();
            $this->insertEmbedding($this->memoryId, 3);
        });
    }

    public function test_memory_has_at_most_one_embedding(): void
    {
        $this->insertEmbedding($this->memoryId, 1536);

        $this->expectException(QueryException::class);

        DB::transaction(function (): void {
            $this->insertEmbedding($this->memoryId, 1536);
        });
    }

    public function test_embedding_is_deleted_with_its_memory(): void
    {
        $this->insertEmbedding($this->memoryId, 1536);

        DB::table('memories')->where('id', $this->memoryId)->delete();

        $this->assertDatabaseMissing('memory_embeddings', ['memory_id' => $this->memoryId]);
    }

    public function test_hnsw_cosine_index_exists(): void
    {
        $definition = DB::table('pg_indexes')
            ->where('tablename', 'memory_embeddings')
            ->where('indexname', 'memory_embeddings_embedding_hnsw')
            ->value('indexdef');

        $this->assertNotNull($definition);
        $this->assertStringContainsString('USING hnsw', $definition);
        $this->assertStringContainsString('vector_cosine_ops', $definition);
    }

    private function insertEmbedding(int $memoryId, int $dimensions): void
    {
        $vector = '['.implode(',', array_fill(0, $dimensions, 0.1)).']';

        DB::table('memory_embeddings')->insert([
            'memory_id' => $memoryId,
            'ai_model_id' => $this->modelId,
            'embedding' => $vector,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLookup(string $table, string $slug): int
    {
        return DB::table($table)->insertGetId([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
