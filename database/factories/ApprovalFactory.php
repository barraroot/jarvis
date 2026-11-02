<?php

namespace Database\Factories;

use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Approval>
 */
class ApprovalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $requestedAt = now();

        return [
            'user_id' => User::factory(),
            'tool_id' => Tool::factory()->write(),
            'approval_status_id' => ApprovalStatus::idFor(ApprovalStatus::PENDING),
            'task_id' => null,
            'agent_execution_id' => null,
            'input' => ['customer_id' => fake()->numberBetween(1, 999)],
            'requested_at' => $requestedAt,
            'expires_at' => $requestedAt->copy()->addHours(24),
            'decided_at' => null,
            'decided_by_user_id' => null,
            'decision_reason' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'approval_status_id' => ApprovalStatus::idFor(ApprovalStatus::PENDING),
            'decided_at' => null,
            'decided_by_user_id' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->decided(ApprovalStatus::APPROVED);
    }

    public function denied(): static
    {
        return $this->decided(ApprovalStatus::DENIED)->state(fn (array $attributes): array => [
            'decision_reason' => fake()->sentence(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'approval_status_id' => ApprovalStatus::idFor(ApprovalStatus::EXPIRED),
            'requested_at' => now()->subHours(48),
            'expires_at' => now()->subHours(24),
        ]);
    }

    private function decided(string $statusSlug): static
    {
        return $this->state(fn (array $attributes): array => [
            'approval_status_id' => ApprovalStatus::idFor($statusSlug),
            'decided_at' => now(),
            'decided_by_user_id' => fn (array $resolved): int => (int) $resolved['user_id'],
        ]);
    }
}
