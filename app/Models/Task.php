<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'agent_id',
    'conversation_id',
    'message_id',
    'task_status_id',
    'retry_of_task_id',
    'title',
    'request',
    'progress',
    'timeout_seconds',
    'result_type_id',
    'result',
    'error',
    'queued_at',
    'started_at',
    'completed_at',
    'visualization_id',
    'voice_session_id',
    'ai_model_id',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * Statuses after which a task no longer changes.
     *
     * @var list<string>
     */
    public const array TERMINAL_STATUSES = [TaskStatus::COMPLETED, TaskStatus::FAILED, TaskStatus::CANCELLED];

    /**
     * Statuses from which the owner may cancel the task (US-10.3).
     *
     * @var list<string>
     */
    public const array CANCELLABLE_STATUSES = [TaskStatus::QUEUED, TaskStatus::RUNNING, TaskStatus::WAITING_APPROVAL];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'timeout_seconds' => 'integer',
            'result' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function statusSlug(): string
    {
        return $this->status->slug;
    }

    public function hasStatus(string ...$slugs): bool
    {
        return in_array($this->statusSlug(), $slugs, true);
    }

    public function isTerminal(): bool
    {
        return $this->hasStatus(...self::TERMINAL_STATUSES);
    }

    public function canBeCancelled(): bool
    {
        return $this->hasStatus(...self::CANCELLABLE_STATUSES);
    }

    public function canBeRetried(): bool
    {
        return $this->hasStatus(TaskStatus::FAILED);
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

    /** @return BelongsTo<Visualization, $this> */
    public function visualization(): BelongsTo
    {
        return $this->belongsTo(Visualization::class);
    }

    /** @return BelongsTo<VoiceSession, $this> */
    public function voiceSession(): BelongsTo
    {
        return $this->belongsTo(VoiceSession::class);
    }

    /**
     * Model chosen when the task was created by voice (CT-04); null for older tasks.
     *
     * @return BelongsTo<AiModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    /** @return BelongsTo<TaskStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'task_status_id');
    }

    /**
     * The task this one was retried from.
     *
     * @return BelongsTo<Task, $this>
     */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_task_id');
    }

    /** @return HasMany<Task, $this> */
    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_task_id');
    }

    /** @return BelongsTo<ResultType, $this> */
    public function resultType(): BelongsTo
    {
        return $this->belongsTo(ResultType::class, 'result_type_id');
    }

    /** @return HasMany<TaskStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(TaskStep::class)->orderBy('position');
    }

    /** @return HasMany<TaskLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(TaskLog::class);
    }

    /** @return HasMany<AgentExecution, $this> */
    public function agentExecutions(): HasMany
    {
        return $this->hasMany(AgentExecution::class);
    }

    /** @return HasMany<Approval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }
}
