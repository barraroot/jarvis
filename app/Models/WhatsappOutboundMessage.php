<?php

namespace App\Models;

use Database\Factories\WhatsappOutboundMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A send or group mutation going through the guard pipeline and the serialized
 * delivery queue (RF-19–RF-29).
 */
#[Fillable([
    'user_id',
    'whatsapp_session_id',
    'whatsapp_outbound_status_id',
    'kind',
    'to_jid',
    'payload',
    'meta',
    'reason',
    'upstream_id',
    'whatsapp_schedule_id',
    'approval_id',
    'delivery_started_at',
    'sent_at',
    'finished_at',
])]
class WhatsappOutboundMessage extends Model
{
    /** @use HasFactory<WhatsappOutboundMessageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    public const array KINDS = [
        'text',
        'image',
        'video',
        'audio',
        'document',
        'location',
        'group_create',
        'group_participants',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'meta' => 'array',
            'delivery_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
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

    /** @return BelongsTo<WhatsappOutboundStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(WhatsappOutboundStatus::class, 'whatsapp_outbound_status_id');
    }

    /** @return BelongsTo<WhatsappSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WhatsappSchedule::class, 'whatsapp_schedule_id');
    }

    /** @return BelongsTo<Approval, $this> */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    /** @return HasMany<WhatsappOutboundRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(WhatsappOutboundRecipient::class);
    }
}
