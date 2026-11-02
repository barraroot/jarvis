<?php

namespace Database\Factories;

use App\Models\McpToolDefinition;
use App\Models\Tool;
use App\Models\ToolType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tool>
 */
class ToolFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'tool_type_id' => ToolType::idFor(ToolType::INTERNAL),
            'mcp_tool_definition_id' => null,
            'identifier' => 'internal.'.Str::snake($name).'_'.fake()->unique()->numberBetween(1, 99999),
            'name' => Str::title($name),
            'description' => fake()->sentence(),
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            'output_schema' => ['type' => 'object', 'properties' => new \stdClass],
            'timeout_seconds' => 30,
            'is_write' => false,
            'is_sensitive' => false,
            'handler_class' => 'App\\Ai\\Tools\\'.Str::studly($name),
            'endpoint_url' => null,
            'http_method' => null,
            'credential' => null,
            'is_active' => true,
        ];
    }

    public function readOnly(): static
    {
        return $this->state(fn (array $attributes): array => ['is_write' => false, 'is_sensitive' => false]);
    }

    public function write(): static
    {
        return $this->state(fn (array $attributes): array => ['is_write' => true]);
    }

    public function sensitive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_sensitive' => true]);
    }

    public function internal(): static
    {
        return $this->state(fn (array $attributes): array => [
            'tool_type_id' => ToolType::idFor(ToolType::INTERNAL),
            'handler_class' => 'App\\Ai\\Tools\\'.Str::studly(fake()->unique()->words(2, true)),
            'endpoint_url' => null,
            'http_method' => null,
            'credential' => null,
            'mcp_tool_definition_id' => null,
        ]);
    }

    public function rest(): static
    {
        return $this->state(fn (array $attributes): array => [
            'tool_type_id' => ToolType::idFor(ToolType::REST),
            'identifier' => 'rest.'.Str::snake(fake()->unique()->words(2, true)).'_'.fake()->unique()->numberBetween(1, 99999),
            'handler_class' => null,
            'endpoint_url' => fake()->url(),
            'http_method' => 'POST',
            'credential' => 'Bearer '.Str::random(32),
            'mcp_tool_definition_id' => null,
        ]);
    }

    public function webhook(): static
    {
        return $this->rest()->state(fn (array $attributes): array => [
            'tool_type_id' => ToolType::idFor(ToolType::WEBHOOK),
            'identifier' => 'webhook.'.Str::snake(fake()->unique()->words(2, true)).'_'.fake()->unique()->numberBetween(1, 99999),
        ]);
    }

    /**
     * A tool discovered on an MCP connector: write and sensitive until classified (US-9.3).
     */
    public function mcp(): static
    {
        return $this->state(fn (array $attributes): array => [
            'tool_type_id' => ToolType::idFor(ToolType::MCP),
            'mcp_tool_definition_id' => McpToolDefinition::factory(),
            'identifier' => 'mcp.'.Str::snake(fake()->unique()->words(2, true)).'_'.fake()->unique()->numberBetween(1, 99999),
            'handler_class' => null,
            'endpoint_url' => null,
            'http_method' => null,
            'credential' => null,
            'is_write' => true,
            'is_sensitive' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
