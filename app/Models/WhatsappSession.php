<?php

namespace App\Models;

use Database\Factories\WhatsappSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The owner's dedicated wuzapi instance (RF-01). Secrets are encrypted at rest
 * and hidden from serialization (RNF-01); the webhook route finds the session
 * by the sha256 of its `webhook_key` (D5).
 */
#[Fillable([
    'user_id',
    'wuzapi_user_id',
    'token',
    'hmac_key',
    'webhook_key',
    'connector_status_id',
    'suspended_at',
    'phone_masked',
    'last_error',
    'last_checked_at',
    'connected_at',
    'next_delivery_at',
])]
#[Hidden(['token', 'hmac_key', 'webhook_key', 'webhook_key_hash'])]
class WhatsappSession extends Model
{
    /** @use HasFactory<WhatsappSessionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'hmac_key' => 'encrypted',
            'webhook_key' => 'encrypted',
            'suspended_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'connected_at' => 'datetime',
            'next_delivery_at' => 'datetime',
        ];
    }

    /**
     * Keep `webhook_key_hash` in sync with the encrypted `webhook_key` (D5).
     */
    protected static function booted(): void
    {
        static::saving(function (WhatsappSession $session): void {
            if ($session->isDirty('webhook_key') || $session->webhook_key_hash === null) {
                $session->webhook_key_hash = self::hashWebhookKey((string) $session->webhook_key);
            }
        });
    }

    public static function hashWebhookKey(string $webhookKey): string
    {
        return hash('sha256', $webhookKey);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isConnected(): bool
    {
        return $this->statusSlug() === ConnectorStatus::CONNECTED;
    }

    public function statusSlug(): ?string
    {
        return $this->status?->slug;
    }

    #[Scope]
    protected function forWebhookKey(Builder $query, string $webhookKey): void
    {
        $query->where('webhook_key_hash', self::hashWebhookKey($webhookKey));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ConnectorStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ConnectorStatus::class, 'connector_status_id');
    }

    /** @return HasMany<WhatsappMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class);
    }

    /** @return HasMany<WhatsappOutboundMessage, $this> */
    public function outboundMessages(): HasMany
    {
        return $this->hasMany(WhatsappOutboundMessage::class);
    }

    /** @return HasMany<WhatsappOutboundRecipient, $this> */
    public function outboundRecipients(): HasMany
    {
        return $this->hasMany(WhatsappOutboundRecipient::class);
    }

    /** @return HasMany<WhatsappSchedule, $this> */
    public function schedules(): HasMany
    {
        return $this->hasMany(WhatsappSchedule::class);
    }
}
