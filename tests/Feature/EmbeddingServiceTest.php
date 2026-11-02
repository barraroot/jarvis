<?php

namespace Tests\Feature;

use App\Exceptions\EmbeddingDimensionMismatchException;
use App\Models\AiModel;
use App\Models\ExecutionStatus;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class EmbeddingServiceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_rejects_vectors_with_wrong_dimension(): void
    {
        $this->setUpSeedsLookups();

        AiModel::factory()->defaultEmbedding()->create();

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => [[0.1, 0.2]]);

        $this->expectException(EmbeddingDimensionMismatchException::class);
        $this->expectExceptionMessage('expected 1536');

        app(EmbeddingService::class)->embed('texto qualquer');
    }

    public function test_records_usage_for_the_embedding_model(): void
    {
        $this->setUpSeedsLookups();

        $user = User::factory()->create();
        $model = AiModel::factory()->defaultEmbedding()->create();

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => [array_fill(0, 1536, 0.001)]);

        app(EmbeddingService::class)->embed('texto qualquer', $user);

        $this->assertDatabaseHas('usage_records', [
            'user_id' => $user->id,
            'ai_model_id' => $model->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
        ]);

        $this->assertSame(1, UsageRecord::query()->count());
    }
}
