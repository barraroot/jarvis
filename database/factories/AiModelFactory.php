<?php

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\AiModelCapability;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModel>
 */
class AiModelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $identifier = 'model-'.fake()->unique()->lexify('??????');

        return [
            'ai_provider_id' => AiProvider::factory(),
            'name' => ucfirst($identifier),
            'model_identifier' => $identifier,
            'context_window' => 128000,
            'input_cost_per_million' => fake()->randomFloat(4, 0.1, 10),
            'output_cost_per_million' => fake()->randomFloat(4, 0.1, 30),
            'is_default_text' => false,
            'is_default_embedding' => false,
            'fallback_order' => null,
            'is_active' => true,
        ];
    }

    /**
     * Attach the `text` capability after creation unless another capability was requested.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (AiModel $model): void {
            if ($model->capabilities()->exists()) {
                return;
            }

            $model->capabilities()->attach(AiModelCapability::idFor(AiModelCapability::TEXT));
        });
    }

    public function defaultText(): static
    {
        return $this->state(fn (array $attributes): array => ['is_default_text' => true]);
    }

    public function defaultEmbedding(): static
    {
        return $this->embeddings()->state(fn (array $attributes): array => ['is_default_embedding' => true]);
    }

    public function embeddings(): static
    {
        return $this
            ->state(fn (array $attributes): array => ['context_window' => 8191])
            ->afterCreating(function (AiModel $model): void {
                $model->capabilities()->sync([AiModelCapability::idFor(AiModelCapability::EMBEDDINGS)]);
            });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    public function fallback(int $order): static
    {
        return $this->state(fn (array $attributes): array => ['fallback_order' => $order]);
    }
}
