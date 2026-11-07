<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recipient counted by the send guards (RF-20–RF-25, RF-29). Only `created_at` is stored.
 */
#[Fillable([
    'whatsapp_outbound_message_id',
    'whatsapp_session_id',
    'jid',
    'is_non_contact',
    'created_at',
])]
class WhatsappOutboundRecipient extends Model
{
    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_non_contact' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WhatsappOutboundMessage, $this> */
    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(WhatsappOutboundMessage::class, 'whatsapp_outbound_message_id');
    }

    /** @return BelongsTo<WhatsappSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class, 'whatsapp_session_id');
    }
}
