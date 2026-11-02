<?php

namespace App\Models;

use Database\Factories\McpToolDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'mcp_server_id',
    'name',
    'description',
    'input_schema',
    'output_schema',
    'is_available',
    'discovered_at',
    'last_seen_at',
])]
class McpToolDefinition extends Model
{
    /** @use HasFactory<McpToolDefinitionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'output_schema' => 'array',
            'is_available' => 'boolean',
            'discovered_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<McpServer, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(McpServer::class, 'mcp_server_id');
    }

    /**
     * The catalog entry generated for this definition (US-9.3).
     *
     * @return HasOne<Tool, $this>
     */
    public function tool(): HasOne
    {
        return $this->hasOne(Tool::class, 'mcp_tool_definition_id');
    }
}
