<?php

namespace App\Models;

use Database\Factories\WhatsappConsentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versioned WhatsApp consent with the scopes the owner accepted (RF-09–RF-13, D1).
 */
#[Fillable([
    'user_id',
    'version',
    'scopes',
    'consented_at',
    'revoked_at',
])]
class WhatsappConsent extends Model
{
    /** @use HasFactory<WhatsappConsentFactory> */
    use HasFactory;

    /**
     * Version of the WhatsApp consent text currently shown (RF-12).
     */
    public const int CURRENT_VERSION = 1;

    public const string SCOPE_READ = 'leitura';

    public const string SCOPE_SEND = 'envio';

    public const string SCOPE_GROUPS = 'grupos';

    public const string SCOPE_MEDIA = 'midia';

    /**
     * Every scope the owner may accept (RF-09).
     *
     * @var list<string>
     */
    public const array SCOPES = [
        self::SCOPE_READ,
        self::SCOPE_SEND,
        self::SCOPE_GROUPS,
        self::SCOPE_MEDIA,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'scopes' => 'array',
            'consented_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Consent counts only when not revoked and given for the current text (RF-10, RF-12).
     */
    public function isValid(): bool
    {
        return $this->revoked_at === null
            && $this->consented_at !== null
            && $this->version >= self::CURRENT_VERSION;
    }

    /**
     * Required scopes the owner has not accepted, in the order they were required (RF-13).
     *
     * @param  list<string>  $requiredScopes
     * @return list<string>
     */
    public function missingScopes(array $requiredScopes): array
    {
        return array_values(array_diff($requiredScopes, $this->scopes ?? []));
    }

    /**
     * Valid consent covering every required scope.
     *
     * @param  list<string>  $requiredScopes
     */
    public function allows(array $requiredScopes): bool
    {
        return $this->isValid() && $this->missingScopes($requiredScopes) === [];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
