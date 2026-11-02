<?php

namespace App\Models;

use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name',
    'slug',
    'description',
    'objective',
    'instructions',
    'ai_model_id',
    'max_delegation_depth',
    'max_tool_calls',
    'timeout_seconds',
    'is_default',
    'is_active',
])]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_delegation_depth' => 'integer',
            'max_tool_calls' => 'integer',
            'timeout_seconds' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Whether this is the Personal Assistant (the single default agent).
     */
    public function isDefault(): bool
    {
        return $this->is_default;
    }

    /**
     * The model this agent runs on: its own, or the global default text model.
     *
     * @throws ModelNotFoundException when neither is configured
     */
    public function effectiveModel(): AiModel
    {
        if ($this->ai_model_id !== null && $this->model !== null) {
            return $this->model;
        }

        $default = AiModel::query()->where('is_default_text', true)->first();

        if ($default === null) {
            throw (new ModelNotFoundException)->setModel(AiModel::class);
        }

        return $default;
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return BelongsTo<AiModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    /**
     * Authorized tools. The pivot only has `created_at`, so both timestamp slots point to it.
     *
     * @return BelongsToMany<Tool, $this>
     */
    public function tools(): BelongsToMany
    {
        return $this->belongsToMany(Tool::class, 'agent_tool')->withTimestamps('created_at', 'created_at');
    }

    /** @return HasMany<AgentExecution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(AgentExecution::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
