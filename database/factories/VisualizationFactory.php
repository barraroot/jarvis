<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use App\Models\Visualization;
use App\Models\VisualizationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visualization>
 */
class VisualizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'task_id' => fn (array $attributes): int => Task::factory()->create(['user_id' => $attributes['user_id']])->id,
            'visualization_status_id' => VisualizationStatus::idFor(VisualizationStatus::SYNCING),
            'title' => fake()->sentence(3),
            'source' => fake()->company(),
            'sections' => [
                [
                    'key' => 'question',
                    'position' => 1,
                    'label' => 'PERGUNTA',
                    'type' => 'question',
                    'headline' => fake()->sentence(),
                ],
            ],
            'closed_at' => null,
        ];
    }

    public function updated(): static
    {
        return $this->status(VisualizationStatus::UPDATED);
    }

    public function failed(): static
    {
        return $this->status(VisualizationStatus::FAILED);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => ['closed_at' => now()]);
    }

    public function status(string $statusSlug): static
    {
        return $this->state(fn (array $attributes): array => [
            'visualization_status_id' => VisualizationStatus::idFor($statusSlug),
        ]);
    }
}
