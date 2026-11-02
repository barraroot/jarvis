<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'conversation_id',
    'user_id',
    'message_role_id',
    'message_source_id',
    'execution_status_id',
    'agent_id',
    'ai_model_id',
    'content',
    'result_type_id',
    'payload',
    'suggested_actions',
    'meta',
])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'suggested_actions' => 'array',
            'meta' => 'array',
        ];
    }

    /**
     * Assistant replies are streaming while their execution status is `running`.
     */
    public function isStreaming(): bool
    {
        return $this->execution_status_id === ExecutionStatus::idFor(ExecutionStatus::RUNNING);
    }

    #[Scope]
    protected function fromUser(Builder $query): void
    {
        $query->where('message_role_id', MessageRole::idFor(MessageRole::USER));
    }

    #[Scope]
    protected function fromAssistant(Builder $query): void
    {
        $query->where('message_role_id', MessageRole::idFor(MessageRole::ASSISTANT));
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MessageRole, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(MessageRole::class, 'message_role_id');
    }

    /** @return BelongsTo<MessageSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(MessageSource::class, 'message_source_id');
    }

    /** @return BelongsTo<ExecutionStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ExecutionStatus::class, 'execution_status_id');
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return BelongsTo<AiModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    /** @return BelongsTo<ResultType, $this> */
    public function resultType(): BelongsTo
    {
        return $this->belongsTo(ResultType::class, 'result_type_id');
    }

    /** @return HasMany<UsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<AgentExecution, $this> */
    public function agentExecutions(): HasMany
    {
        return $this->hasMany(AgentExecution::class);
    }
}
