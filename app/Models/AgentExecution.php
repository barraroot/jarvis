<?php

namespace App\Models;

use Database\Factories\AgentExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'agent_id',
    'execution_status_id',
    'parent_agent_execution_id',
    'depth',
    'task_id',
    'conversation_id',
    'message_id',
    'tool_call_count',
    'error',
    'meta',
    'started_at',
    'finished_at',
])]
class AgentExecution extends Model
{
    /** @use HasFactory<AgentExecutionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'tool_call_count' => 'integer',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Agent ids of every ancestor execution, nearest parent first, up to the root (US-7.3 cycle check).
     *
     * @return list<int>
     */
    public function ancestorAgentIds(): array
    {
        $ids = [];
        $parentId = $this->parent_agent_execution_id;
        $visited = [$this->id];

        while ($parentId !== null && ! in_array($parentId, $visited, true)) {
            $visited[] = $parentId;

            /** @var object{agent_id: int, parent_agent_execution_id: int|null}|null $parent */
            $parent = static::query()
                ->whereKey($parentId)
                ->toBase()
                ->first(['agent_id', 'parent_agent_execution_id']);

            if ($parent === null) {
                break;
            }

            $ids[] = (int) $parent->agent_id;
            $parentId = $parent->parent_agent_execution_id;
        }

        return $ids;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return BelongsTo<ExecutionStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ExecutionStatus::class, 'execution_status_id');
    }

    /** @return BelongsTo<AgentExecution, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_agent_execution_id');
    }

    /** @return HasMany<AgentExecution, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_agent_execution_id');
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return HasMany<ToolExecution, $this> */
    public function toolExecutions(): HasMany
    {
        return $this->hasMany(ToolExecution::class);
    }

    /** @return HasMany<UsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    /** @return HasMany<Approval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }
}
