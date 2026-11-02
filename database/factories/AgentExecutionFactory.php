<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ExecutionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentExecution>
 */
class AgentExecutionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'agent_id' => Agent::factory(),
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::PENDING),
            'parent_agent_execution_id' => null,
            'depth' => 0,
            'task_id' => null,
            'conversation_id' => null,
            'message_id' => null,
            'tool_call_count' => 0,
            'error' => null,
            'meta' => null,
            'started_at' => now(),
            'finished_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::RUNNING),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::FAILED),
            'finished_at' => now(),
            'error' => fake()->sentence(),
        ]);
    }

    /**
     * A delegated execution: same user, task and conversation as the parent, one level deeper.
     */
    public function childOf(AgentExecution $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $parent->user_id,
            'parent_agent_execution_id' => $parent->id,
            'depth' => $parent->depth + 1,
            'task_id' => $parent->task_id,
            'conversation_id' => $parent->conversation_id,
            'message_id' => $parent->message_id,
        ]);
    }
}
