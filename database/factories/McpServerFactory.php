<?php

namespace Database\Factories;

use App\Models\ConnectorStatus;
use App\Models\McpServer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<McpServer>
 */
class McpServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::PENDING),
            'name' => fake()->unique()->company().' MCP '.fake()->unique()->numberBetween(1, 99999),
            'url' => 'https://'.fake()->domainName().'/mcp',
            'credential' => Str::random(32),
            'is_active' => true,
            'last_synced_at' => null,
            'last_checked_at' => null,
            'last_error' => null,
        ];
    }

    /**
     * Admin-managed connector shared with every user.
     */
    public function global(): static
    {
        return $this->state(fn (array $attributes): array => ['user_id' => null]);
    }

    public function connected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::CONNECTED),
            'last_synced_at' => now(),
            'last_checked_at' => now(),
            'last_error' => null,
        ]);
    }

    public function errored(): static
    {
        return $this->state(fn (array $attributes): array => [
            'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::ERROR),
            'last_checked_at' => now(),
            'last_error' => 'Connection refused',
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::DISCONNECTED),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
