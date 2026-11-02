<?php

namespace Database\Factories;

use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'audit_action_id' => AuditAction::idFor(AuditAction::LOGIN_SUCCEEDED),
            'user_id' => User::factory(),
            'subject_type' => null,
            'subject_id' => null,
            'properties' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'created_at' => now(),
        ];
    }

    public function action(string $actionSlug): static
    {
        return $this->state(fn (array $attributes): array => [
            'audit_action_id' => AuditAction::idFor($actionSlug),
        ]);
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes): array => ['user_id' => null]);
    }

    public function about(Model $subject): static
    {
        return $this->state(fn (array $attributes): array => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }
}
