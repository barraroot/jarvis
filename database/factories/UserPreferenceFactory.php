<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPreference>
 */
class UserPreferenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'locale' => 'pt-BR',
            'speech_locale' => 'pt-BR',
            'voice_name' => null,
            'speech_rate' => 1.00,
            'spoken_responses_enabled' => true,
            'wake_word_enabled' => false,
            'wake_word_consented_at' => null,
            'wake_word_consent_version' => null,
            'silence_timeout_ms' => 1500,
            'extra' => null,
        ];
    }

    /**
     * Wake word enabled with the privacy notice accepted (US-4.4).
     */
    public function wakeWordEnabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'wake_word_enabled' => true,
            'wake_word_consented_at' => now(),
            'wake_word_consent_version' => UserPreference::CURRENT_WAKE_WORD_CONSENT_VERSION,
        ]);
    }

    public function silent(): static
    {
        return $this->state(fn (array $attributes): array => ['spoken_responses_enabled' => false]);
    }
}
