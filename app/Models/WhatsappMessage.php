<?php

namespace App\Models;

use Database\Factories\WhatsappMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message received through the webhook (RF-35–RF-38). Untrusted content: media
 * keeps only metadata.
 */
#[Fillable([
    'whatsapp_session_id',
    'user_id',
    'upstream_id',
    'chat_jid',
    'sender_jid',
    'from_me',
    'type',
    'text',
    'media',
    'whatsapp_outbound_message_id',
    'sent_at',
])]
class WhatsappMessage extends Model
{
    /** @use HasFactory<WhatsappMessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_me' => 'boolean',
            'media' => 'array',
            'sent_at' => 'datetime',
        ];
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

    /** @return BelongsTo<WhatsappOutboundMessage, $this> */
    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(WhatsappOutboundMessage::class, 'whatsapp_outbound_message_id');
    }
}
