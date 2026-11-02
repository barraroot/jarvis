<?php

namespace Database\Factories;

use App\Models\LogLevel;
use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskLog>
 */
class TaskLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'task_step_id' => null,
            'log_level_id' => LogLevel::idFor(LogLevel::INFO),
            'message' => fake()->sentence(),
            'context' => null,
            'created_at' => now(),
        ];
    }

    public function level(string $levelSlug): static
    {
        return $this->state(fn (array $attributes): array => [
            'log_level_id' => LogLevel::idFor($levelSlug),
        ]);
    }

    public function error(): static
    {
        return $this->level(LogLevel::ERROR);
    }
}
