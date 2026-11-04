<?php

namespace App\Models;

use Database\Factories\UserPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'locale',
    'speech_locale',
    'voice_name',
    'speech_rate',
    'spoken_responses_enabled',
    'wake_word_enabled',
    'wake_word_consented_at',
    'wake_word_consent_version',
    'silence_timeout_ms',
    'extra',
])]
class UserPreference extends Model
{
    /** @use HasFactory<UserPreferenceFactory> */
    use HasFactory;

    /**
     * Version of the wake word consent text currently shown (RF-22). Version 2
     * discloses local detection and audio sent to OpenAI while listening.
     */
    public const int CURRENT_WAKE_WORD_CONSENT_VERSION = 2;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'speech_rate' => 'decimal:2',
            'spoken_responses_enabled' => 'boolean',
            'wake_word_enabled' => 'boolean',
            'wake_word_consented_at' => 'datetime',
            'wake_word_consent_version' => 'integer',
            'silence_timeout_ms' => 'integer',
            'extra' => 'array',
        ];
    }

    /**
     * Consent counts only when given for the current consent text (RF-22).
     */
    public function hasValidWakeWordConsent(): bool
    {
        return $this->wake_word_consented_at !== null
            && $this->wake_word_consent_version !== null
            && $this->wake_word_consent_version >= self::CURRENT_WAKE_WORD_CONSENT_VERSION;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
