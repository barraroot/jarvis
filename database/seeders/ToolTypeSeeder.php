<?php

namespace Database\Seeders;

class ToolTypeSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'tool_types';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'internal' => ['name' => 'Internal'],
            'mcp' => ['name' => 'MCP'],
            'rest' => ['name' => 'Rest'],
            'webhook' => ['name' => 'Webhook'],
        ];
    }
}
