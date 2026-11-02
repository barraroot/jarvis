<?php

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\Memory;
use App\Models\MemoryEmbedding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemoryEmbedding>
 */
class MemoryEmbeddingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'memory_id' => Memory::factory(),
            'ai_model_id' => AiModel::factory()->embeddings(),
            'embedding' => static::fakeVector(),
        ];
    }

    /**
     * Use a specific vector; shorter arrays are zero-padded to 1536 dimensions.
     *
     * @param  array<int, float>  $values
     */
    public function vector(array $values): static
    {
        return $this->state(fn (array $attributes): array => [
            'embedding' => static::fakeVector($values),
        ]);
    }

    /**
     * Build a unit-length 1536-dimension vector, optionally seeded with leading values.
     *
     * @param  array<int, float>  $leading
     * @return array<int, float>
     */
    public static function fakeVector(array $leading = []): array
    {
        $vector = array_pad(array_values($leading), MemoryEmbedding::DIMENSIONS, 0.0);

        if ($leading === []) {
            $vector = array_map(fn (): float => fake()->randomFloat(6, -1, 1), $vector);
        }

        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $vector)));

        if ($norm === 0.0) {
            $vector[0] = 1.0;

            return $vector;
        }

        return array_map(fn (float $value): float => $value / $norm, $vector);
    }
}
