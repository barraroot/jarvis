<?php

namespace App\Models;

use Database\Factories\WhatsappScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One-off or recurring WhatsApp send approved once by the owner (RF-30–RF-34).
 */
#[Fillable([
    'user_id',
    'whatsapp_session_id',
    'approval_id',
    'whatsapp_schedule_status_id',
    'to_jid',
    'kind',
    'payload',
    'run_at',
    'recurrence',
    'next_run_at',
    'last_run_at',
    'approval_expires_at',
])]
class WhatsappSchedule extends Model
{
    /** @use HasFactory<WhatsappScheduleFactory> */
    use HasFactory;

    /**
     * Dates and cron expressions are interpreted in this timezone (RF-30, C-02).
     */
    public const string TIMEZONE = 'America/Sao_Paulo';

    public const string KIND_TEXT = 'text';

    /**
     * Schedule kinds that carry media and require the `midia` scope.
     *
     * @var list<string>
     */
    public const array MEDIA_KINDS = ['image', 'video', 'audio', 'document'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'approval_expires_at' => 'datetime',
        ];
    }

    public function isRecurring(): bool
    {
        return $this->recurrence !== null;
    }

    public function isMedia(): bool
    {
        return in_array($this->kind, self::MEDIA_KINDS, true);
    }

    public function statusSlug(): ?string
    {
        return $this->status?->slug;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<WhatsappSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class, 'whatsapp_session_id');
    }

    /** @return BelongsTo<Approval, $this> */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    /** @return BelongsTo<WhatsappScheduleStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(WhatsappScheduleStatus::class, 'whatsapp_schedule_status_id');
    }

    /** @return HasMany<WhatsappOutboundMessage, $this> */
    public function outboundMessages(): HasMany
    {
        return $this->hasMany(WhatsappOutboundMessage::class);
    }
}
