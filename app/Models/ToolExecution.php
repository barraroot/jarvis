<?php

namespace App\Models;

use Database\Factories\ToolExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tool_id',
    'user_id',
    'execution_status_id',
    'agent_execution_id',
    'task_step_id',
    'approval_id',
    'input',
    'output',
    'error',
    'duration_ms',
    'started_at',
    'finished_at',
])]
class ToolExecution extends Model
{
    /** @use HasFactory<ToolExecutionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tool, $this> */
    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ExecutionStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ExecutionStatus::class, 'execution_status_id');
    }

    /** @return BelongsTo<AgentExecution, $this> */
    public function agentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class);
    }

    /** @return BelongsTo<TaskStep, $this> */
    public function taskStep(): BelongsTo
    {
        return $this->belongsTo(TaskStep::class);
    }

    /** @return BelongsTo<Approval, $this> */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}
