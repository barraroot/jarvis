<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'notification_type_id' => NotificationType::idFor(NotificationType::TASK_COMPLETED),
            'title' => fake()->sentence(3),
            'body' => fake()->sentence(),
            'data' => ['task_id' => fake()->numberBetween(1, 999)],
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes): array => ['read_at' => now()]);
    }

    public function type(string $typeSlug): static
    {
        return $this->state(fn (array $attributes): array => [
            'notification_type_id' => NotificationType::idFor($typeSlug),
        ]);
    }
}
