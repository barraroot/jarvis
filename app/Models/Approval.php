<?php

namespace App\Models;

use Database\Factories\ApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'tool_id',
    'approval_status_id',
    'task_id',
    'agent_execution_id',
    'input',
    'requested_at',
    'expires_at',
    'decided_at',
    'decided_by_user_id',
    'decision_reason',
])]
class Approval extends Model
{
    /** @use HasFactory<ApprovalFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input' => 'array',
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->approval_status_id === ApprovalStatus::idFor(ApprovalStatus::PENDING);
    }

    /**
     * Whether the approval window has closed: already marked expired, or still pending past `expires_at`.
     */
    public function isExpired(): bool
    {
        if ($this->approval_status_id === ApprovalStatus::idFor(ApprovalStatus::EXPIRED)) {
            return true;
        }

        return $this->isPending() && $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Tool, $this> */
    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }

    /** @return BelongsTo<ApprovalStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ApprovalStatus::class, 'approval_status_id');
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<AgentExecution, $this> */
    public function agentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** @return HasOne<ToolExecution, $this> */
    public function toolExecution(): HasOne
    {
        return $this->hasOne(ToolExecution::class);
    }
}
