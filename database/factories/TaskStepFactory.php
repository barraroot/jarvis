<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskStep>
 */
class TaskStepFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'task_status_id' => TaskStatus::idFor(TaskStatus::PENDING),
            'position' => fn (array $attributes): int => (int) TaskStep::query()->where('task_id', $attributes['task_id'])->max('position') + 1,
            'name' => fake()->sentence(3),
            'output' => null,
            'error' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'task_status_id' => TaskStatus::idFor(TaskStatus::RUNNING),
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'task_status_id' => TaskStatus::idFor(TaskStatus::COMPLETED),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'output' => ['ok' => true],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'task_status_id' => TaskStatus::idFor(TaskStatus::FAILED),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'error' => fake()->sentence(),
        ]);
    }
}
