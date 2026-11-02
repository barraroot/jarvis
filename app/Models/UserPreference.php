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
    'silence_timeout_ms',
    'extra',
])]
class UserPreference extends Model
{
    /** @use HasFactory<UserPreferenceFactory> */
    use HasFactory;

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
            'silence_timeout_ms' => 'integer',
            'extra' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
