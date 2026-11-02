<?php

namespace Database\Seeders;

use App\Models\Tool;
use App\Models\ToolType;
use App\Tools\RememberMemoryTool;
use Illuminate\Database\Seeder;

/**
 * Registers the internal `memory.remember` tool in the unified catalog
 * (US-6.1, US-8.1). It is a write-flagged-free read of the model into memory,
 * never sensitive: saving is driven by the user's explicit request.
 */
class RememberMemoryToolSeeder extends Seeder
{
    public function run(): void
    {
        Tool::query()->updateOrCreate(
            ['identifier' => 'memory.remember'],
            [
                'tool_type_id' => ToolType::idFor(ToolType::INTERNAL),
                'mcp_tool_definition_id' => null,
                'name' => 'memory.remember',
                'description' => 'Salva uma informação confirmada na memória de longo prazo do usuário.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                        'category' => ['type' => 'string', 'enum' => ['preference', 'project', 'context', 'learning', 'fact']],
                    ],
                    'required' => ['content', 'category'],
                ],
                'output_schema' => ['type' => 'string'],
                'timeout_seconds' => 30,
                'is_write' => false,
                'is_sensitive' => false,
                'handler_class' => RememberMemoryTool::class,
                'is_active' => true,
            ],
        );
    }
}
