<?php

namespace Database\Factories;

use App\Models\ExecutionStatus;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ToolExecution>
 */
class ToolExecutionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tool_id' => Tool::factory(),
            'user_id' => User::factory(),
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'agent_execution_id' => null,
            'task_step_id' => null,
            'approval_id' => null,
            'input' => ['query' => fake()->word()],
            'output' => ['result' => fake()->sentence()],
            'error' => null,
            'duration_ms' => fake()->numberBetween(10, 5000),
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::RUNNING),
            'output' => null,
            'duration_ms' => null,
            'finished_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::FAILED),
            'output' => null,
            'error' => fake()->sentence(),
        ]);
    }

    public function timedOut(): static
    {
        return $this->state(fn (array $attributes): array => [
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::TIMED_OUT),
            'output' => null,
            'error' => 'Tool execution timed out',
        ]);
    }
}
