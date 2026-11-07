<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WhatsappConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappConsent>
 */
class WhatsappConsentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'version' => WhatsappConsent::CURRENT_VERSION,
            'scopes' => WhatsappConsent::SCOPES,
            'consented_at' => now(),
            'revoked_at' => null,
        ];
    }

    /**
     * @param  list<string>  $scopes
     */
    public function scopes(array $scopes): static
    {
        return $this->state(fn (array $attributes): array => ['scopes' => $scopes]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => ['revoked_at' => now()]);
    }

    /**
     * Accepted for a text older than the current one (RF-12).
     */
    public function outdated(): static
    {
        return $this->state(fn (array $attributes): array => ['version' => WhatsappConsent::CURRENT_VERSION - 1]);
    }
}
