<?php

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\ExecutionStatus;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageRecord>
 */
class UsageRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $inputTokens = fake()->numberBetween(50, 5000);
        $outputTokens = fake()->numberBetween(10, 2000);

        return [
            'user_id' => User::factory(),
            'ai_model_id' => AiModel::factory(),
            'ai_provider_id' => fn (array $attributes): int => AiModel::query()->findOrFail($attributes['ai_model_id'])->ai_provider_id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'agent_execution_id' => null,
            'message_id' => null,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'estimated_cost' => fn (array $attributes): float => AiModel::query()->findOrFail($attributes['ai_model_id'])->costFor($inputTokens, $outputTokens),
            'latency_ms' => fake()->numberBetween(100, 8000),
            'created_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::FAILED),
        ]);
    }
}
