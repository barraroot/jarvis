<?php

namespace Database\Factories;

use App\Models\Memory;
use App\Models\MemoryCategory;
use App\Models\MemoryStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Memory>
 */
class MemoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'memory_status_id' => MemoryStatus::idFor(MemoryStatus::CONFIRMED),
            'memory_category_id' => MemoryCategory::idFor(fake()->randomElement([
                MemoryCategory::PREFERENCE,
                MemoryCategory::PROJECT,
                MemoryCategory::CONTEXT,
                MemoryCategory::LEARNING,
                MemoryCategory::FACT,
            ])),
            'content' => fake()->sentence(),
            'source_conversation_id' => null,
            'source_message_id' => null,
            'confirmed_at' => now(),
            'last_used_at' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'memory_status_id' => MemoryStatus::idFor(MemoryStatus::CONFIRMED),
            'confirmed_at' => now(),
        ]);
    }

    public function hypothesis(): static
    {
        return $this->state(fn (array $attributes): array => [
            'memory_status_id' => MemoryStatus::idFor(MemoryStatus::HYPOTHESIS),
            'confirmed_at' => null,
        ]);
    }

    public function category(string $categorySlug): static
    {
        return $this->state(fn (array $attributes): array => [
            'memory_category_id' => MemoryCategory::idFor($categorySlug),
        ]);
    }
}
