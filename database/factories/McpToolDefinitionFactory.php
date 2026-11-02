<?php

namespace Database\Factories;

use App\Models\McpServer;
use App\Models\McpToolDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<McpToolDefinition>
 */
class McpToolDefinitionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mcp_server_id' => McpServer::factory(),
            'name' => Str::snake(fake()->unique()->words(2, true)).'_'.fake()->unique()->numberBetween(1, 99999),
            'description' => fake()->sentence(),
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            'output_schema' => null,
            'is_available' => true,
            'discovered_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn (array $attributes): array => ['is_available' => false]);
    }
}
