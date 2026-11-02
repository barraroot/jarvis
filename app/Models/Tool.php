<?php

namespace App\Models;

use Database\Factories\ToolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tool_type_id',
    'mcp_tool_definition_id',
    'identifier',
    'name',
    'description',
    'input_schema',
    'output_schema',
    'timeout_seconds',
    'is_write',
    'is_sensitive',
    'handler_class',
    'endpoint_url',
    'http_method',
    'credential',
    'is_active',
])]
#[Hidden(['credential'])]
class Tool extends Model
{
    /** @use HasFactory<ToolFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'output_schema' => 'array',
            'timeout_seconds' => 'integer',
            'is_write' => 'boolean',
            'is_sensitive' => 'boolean',
            'credential' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Write or sensitive tools must be approved by the owner before running (US-8.1).
     */
    public function requiresApproval(): bool
    {
        return $this->is_write || $this->is_sensitive;
    }

    /**
     * Masked representation of the credential (US-8.1): `****` followed by the last four characters.
     *
     * @return Attribute<string|null, never>
     */
    protected function maskedCredential(): Attribute
    {
        return Attribute::get(function (): ?string {
            $credential = $this->credential;

            if ($credential === null || $credential === '') {
                return null;
            }

            return '****'.substr($credential, -4);
        });
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    #[Scope]
    protected function readOnly(Builder $query): void
    {
        $query->where('is_write', false);
    }

    /** @return BelongsTo<ToolType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ToolType::class, 'tool_type_id');
    }

    /** @return BelongsTo<McpToolDefinition, $this> */
    public function mcpToolDefinition(): BelongsTo
    {
        return $this->belongsTo(McpToolDefinition::class, 'mcp_tool_definition_id');
    }

    /** @return BelongsToMany<Agent, $this> */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_tool')->withTimestamps('created_at', 'created_at');
    }

    /** @return HasMany<ToolExecution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(ToolExecution::class);
    }

    /** @return HasMany<Approval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }
}
