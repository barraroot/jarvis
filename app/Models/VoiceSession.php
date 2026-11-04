<?php

namespace App\Models;

use App\Support\Settings;
use Database\Factories\VoiceSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Realtime wake session (RF-21): one conversation, one realtime model and the
 * running total of tokens reported by the SPA (RF-10a).
 */
#[Fillable([
    'user_id',
    'conversation_id',
    'ai_model_id',
    'total_tokens',
    'last_issued_at',
    'last_activity_at',
])]
class VoiceSession extends Model
{
    /** @use HasFactory<VoiceSessionFactory> */
    use HasFactory;

    /**
     * Minutes without activity after which the session no longer accepts voice
     * tasks (D11): above the 960 s `RunTask` timeout plus the 300 s maximum
     * listening window, so a window kept open by RF-04a is never rejected.
     */
    public const int ACTIVE_TTL_MINUTES = 30;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_tokens' => 'integer',
            'last_issued_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Bump `last_activity_at` to now and persist it (D11).
     */
    public function touchActivity(): void
    {
        $this->last_activity_at = now();

        $this->save();
    }

    /**
     * Active ⇔ activity within the TTL and the reported tokens still below the
     * per-session ceiling (D11, CT-02).
     */
    public function isActive(Settings $settings): bool
    {
        if ($this->last_activity_at === null) {
            return false;
        }

        if ($this->last_activity_at->lt(now()->subMinutes(self::ACTIVE_TTL_MINUTES))) {
            return false;
        }

        return $this->total_tokens < $settings->sessionTokenCeiling();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<AiModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
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
}
