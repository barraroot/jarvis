<?php

namespace App\Models;

use Database\Factories\MemoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'memory_status_id',
    'memory_category_id',
    'content',
    'source_conversation_id',
    'source_message_id',
    'confirmed_at',
    'last_used_at',
])]
class Memory extends Model
{
    /** @use HasFactory<MemoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function isConfirmed(): bool
    {
        return $this->memory_status_id === MemoryStatus::idFor(MemoryStatus::CONFIRMED);
    }

    #[Scope]
    protected function confirmed(Builder $query): void
    {
        $query->where('memory_status_id', MemoryStatus::idFor(MemoryStatus::CONFIRMED));
    }

    #[Scope]
    protected function hypotheses(Builder $query): void
    {
        $query->where('memory_status_id', MemoryStatus::idFor(MemoryStatus::HYPOTHESIS));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MemoryStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(MemoryStatus::class, 'memory_status_id');
    }

    /** @return BelongsTo<MemoryCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MemoryCategory::class, 'memory_category_id');
    }

    /** @return BelongsTo<Conversation, $this> */
    public function sourceConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'source_conversation_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /** @return HasOne<MemoryEmbedding, $this> */
    public function embedding(): HasOne
    {
        return $this->hasOne(MemoryEmbedding::class);
    }
}
