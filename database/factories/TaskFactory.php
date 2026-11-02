<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\ResultType;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'agent_id' => Agent::factory(),
            'conversation_id' => null,
            'message_id' => null,
            'task_status_id' => TaskStatus::idFor(TaskStatus::PENDING),
            'retry_of_task_id' => null,
            'title' => fake()->sentence(3),
            'request' => fake()->sentence(),
            'progress' => 0,
            'timeout_seconds' => 900,
            'result_type_id' => null,
            'result' => null,
            'error' => null,
            'queued_at' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->status(TaskStatus::PENDING);
    }

    public function queued(): static
    {
        return $this->status(TaskStatus::QUEUED)->state(fn (array $attributes): array => [
            'queued_at' => now(),
        ]);
    }

    public function running(): static
    {
        return $this->status(TaskStatus::RUNNING)->state(fn (array $attributes): array => [
            'queued_at' => now()->subMinute(),
            'started_at' => now(),
            'progress' => fake()->numberBetween(1, 99),
        ]);
    }

    public function waitingApproval(): static
    {
        return $this->status(TaskStatus::WAITING_APPROVAL)->state(fn (array $attributes): array => [
            'queued_at' => now()->subMinutes(2),
            'started_at' => now()->subMinute(),
            'progress' => fake()->numberBetween(1, 99),
        ]);
    }

    public function completed(): static
    {
        return $this->status(TaskStatus::COMPLETED)->state(fn (array $attributes): array => [
            'queued_at' => now()->subMinutes(3),
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
            'progress' => 100,
            'result_type_id' => ResultType::idFor(ResultType::TEXT),
            'result' => ['text' => fake()->sentence()],
        ]);
    }

    public function failed(): static
    {
        return $this->status(TaskStatus::FAILED)->state(fn (array $attributes): array => [
            'queued_at' => now()->subMinutes(3),
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
            'error' => fake()->sentence(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->status(TaskStatus::CANCELLED)->state(fn (array $attributes): array => [
            'queued_at' => now()->subMinutes(3),
            'completed_at' => now(),
        ]);
    }

    public function retryOf(Task $original): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $original->user_id,
            'agent_id' => $original->agent_id,
            'conversation_id' => $original->conversation_id,
            'message_id' => $original->message_id,
            'retry_of_task_id' => $original->id,
            'title' => $original->title,
            'request' => $original->request,
        ]);
    }

    public function status(string $statusSlug): static
    {
        return $this->state(fn (array $attributes): array => [
            'task_status_id' => TaskStatus::idFor($statusSlug),
        ]);
    }
}
