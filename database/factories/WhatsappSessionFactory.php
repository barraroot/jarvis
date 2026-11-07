<?php

namespace Database\Factories;

use App\Models\ConnectorStatus;
use App\Models\User;
use App\Models\WhatsappConsent;
use App\Models\WhatsappSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsappSession>
 */
class WhatsappSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'wuzapi_user_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'token' => Str::random(40),
            'hmac_key' => Str::random(40),
            'webhook_key' => Str::random(48),
            'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::PENDING),
            'suspended_at' => null,
            'phone_masked' => null,
            'last_error' => null,
            'last_checked_at' => null,
            'connected_at' => null,
            'next_delivery_at' => null,
        ];
    }

    public function connected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::CONNECTED),
            'phone_masked' => '+55 11 ****-'.fake()->numerify('####'),
            'last_checked_at' => now(),
            'connected_at' => now(),
            'last_error' => null,
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::DISCONNECTED),
        ]);
    }

    /**
     * Suspended by an administrator (RF-41).
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => ['suspended_at' => now()]);
    }

    /**
     * Owner holds a valid consent with the given scopes (RF-09).
     *
     * @param  list<string>  $scopes
     */
    public function withConsent(array $scopes = WhatsappConsent::SCOPES): static
    {
        return $this->afterCreating(function (WhatsappSession $session) use ($scopes): void {
            WhatsappConsent::query()->updateOrCreate(
                ['user_id' => $session->user_id],
                [
                    'version' => WhatsappConsent::CURRENT_VERSION,
                    'scopes' => $scopes,
                    'consented_at' => now(),
                    'revoked_at' => null,
                ],
            );
        });
    }
}
