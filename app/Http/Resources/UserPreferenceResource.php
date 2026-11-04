<?php

namespace App\Http\Resources;

use App\Models\UserPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `wake_word_enabled` is the effective value: a consent given for an older
 * consent text keeps the wake word off until accepted again (RF-22).
 *
 * @mixin UserPreference
 */
class UserPreferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'locale' => $this->locale,
            'speech_locale' => $this->speech_locale,
            'voice_name' => $this->voice_name,
            'speech_rate' => (float) $this->speech_rate,
            'spoken_responses_enabled' => $this->spoken_responses_enabled,
            'wake_word_enabled' => (bool) $this->wake_word_enabled && $this->hasValidWakeWordConsent(),
            'wake_word_consent_valid' => $this->hasValidWakeWordConsent(),
            'wake_word_consented_at' => $this->wake_word_consented_at?->toIso8601String(),
            'wake_word_consent_version' => $this->wake_word_consent_version,
            'silence_timeout_ms' => $this->silence_timeout_ms,
            'extra' => $this->extra,
        ];
    }
}
