<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiModelCapability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AiModelTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_cost_for_tokens_uses_per_million_rates(): void
    {
        $model = AiModel::factory()->create([
            'input_cost_per_million' => 2.5,
            'output_cost_per_million' => 10,
        ]);

        $this->assertSame(0.0, $model->costFor(0, 0));
        $this->assertSame(2.5, $model->costFor(1_000_000, 0));
        $this->assertSame(10.0, $model->costFor(0, 1_000_000));
        $this->assertSame(0.0125, $model->costFor(1_000, 1_000));
        $this->assertSame(0.00025, $model->costFor(100, 0));
    }

    public function test_capability_scopes_and_fallback_order(): void
    {
        $text = AiModel::factory()->create();
        $embedding = AiModel::factory()->embeddings()->create();
        $second = AiModel::factory()->fallback(2)->create();
        $first = AiModel::factory()->fallback(1)->create();

        $this->assertTrue($text->hasCapability(AiModelCapability::TEXT));
        $this->assertFalse($text->hasCapability(AiModelCapability::EMBEDDINGS));

        $this->assertEqualsCanonicalizing([$text->id, $second->id, $first->id], AiModel::text()->pluck('id')->all());
        $this->assertSame([$embedding->id], AiModel::embeddings()->pluck('id')->all());
        $this->assertSame([$first->id, $second->id], AiModel::inFallbackOrder()->pluck('id')->all());
    }

    public function test_default_states_and_relations(): void
    {
        $defaultText = AiModel::factory()->defaultText()->create();
        $defaultEmbedding = AiModel::factory()->defaultEmbedding()->create();
        AiModel::factory()->inactive()->create();

        $this->assertTrue($defaultText->is_default_text);
        $this->assertTrue($defaultEmbedding->is_default_embedding);
        $this->assertTrue($defaultEmbedding->hasCapability(AiModelCapability::EMBEDDINGS));
        $this->assertTrue($defaultText->provider->models->contains($defaultText));
        $this->assertSame(2, AiModel::active()->count());
    }
}
