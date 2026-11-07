<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundStatus;
use App\Models\WhatsappSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappOutboundMessage>
 */
class WhatsappOutboundMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'whatsapp_session_id' => fn (array $attributes): int => WhatsappSession::query()->where('user_id', $attributes['user_id'])->value('id')
                ?? WhatsappSession::factory()->create(['user_id' => $attributes['user_id']])->id,
            'whatsapp_outbound_status_id' => WhatsappOutboundStatus::idFor(WhatsappOutboundStatus::QUEUED),
            'kind' => 'text',
            'to_jid' => '55119'.fake()->numerify('########').'@s.whatsapp.net',
            'payload' => ['text' => fake()->sentence()],
            'meta' => null,
            'reason' => null,
            'upstream_id' => null,
            'whatsapp_schedule_id' => null,
            'approval_id' => null,
            'delivery_started_at' => null,
            'sent_at' => null,
            'finished_at' => null,
        ];
    }

    public function queued(): static
    {
        return $this->status(WhatsappOutboundStatus::QUEUED);
    }

    public function sent(): static
    {
        return $this->status(WhatsappOutboundStatus::SENT)->state(fn (array $attributes): array => [
            'upstream_id' => strtoupper(fake()->unique()->bothify('3EB0################')),
            'delivery_started_at' => now(),
            'sent_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->status(WhatsappOutboundStatus::FAILED)->state(fn (array $attributes): array => [
            'reason' => 'whatsapp_upstream_error',
            'delivery_started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public function blocked(string $reason = 'whatsapp_rate_limited'): static
    {
        return $this->status(WhatsappOutboundStatus::BLOCKED)->state(fn (array $attributes): array => [
            'reason' => $reason,
            'finished_at' => now(),
        ]);
    }

    public function status(string $statusSlug): static
    {
        return $this->state(fn (array $attributes): array => [
            'whatsapp_outbound_status_id' => WhatsappOutboundStatus::idFor($statusSlug),
        ]);
    }

    /**
     * Attach to an existing session and its owner.
     */
    public function forSession(WhatsappSession $session): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $session->user_id,
            'whatsapp_session_id' => $session->id,
        ]);
    }
}
