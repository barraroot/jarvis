<?php

namespace App\Http\Resources;

use App\Models\UserPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
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
            'wake_word_enabled' => $this->wake_word_enabled,
            'wake_word_consented_at' => $this->wake_word_consented_at?->toIso8601String(),
            'silence_timeout_ms' => $this->silence_timeout_ms,
            'extra' => $this->extra,
        ];
    }
}
