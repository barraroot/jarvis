<?php

namespace Database\Factories;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle().' '.fake()->unique()->numberBetween(1, 99999);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'objective' => fake()->sentence(),
            'instructions' => fake()->paragraph(),
            'ai_model_id' => null,
            'max_delegation_depth' => 3,
            'max_tool_calls' => 20,
            'timeout_seconds' => 900,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    /**
     * The Personal Assistant (only one agent may be default).
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Personal Assistant',
            'slug' => 'personal-assistant',
            'is_default' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
