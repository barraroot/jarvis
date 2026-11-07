<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappMessage>
 */
class WhatsappMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $chatJid = '55119'.fake()->numerify('########').'@s.whatsapp.net';

        return [
            'user_id' => User::factory(),
            'whatsapp_session_id' => fn (array $attributes): int => WhatsappSession::query()->where('user_id', $attributes['user_id'])->value('id')
                ?? WhatsappSession::factory()->create(['user_id' => $attributes['user_id']])->id,
            'upstream_id' => strtoupper(fake()->unique()->bothify('3EB0################')),
            'chat_jid' => $chatJid,
            'sender_jid' => $chatJid,
            'from_me' => false,
            'type' => 'text',
            'text' => fake()->sentence(),
            'media' => null,
            'whatsapp_outbound_message_id' => null,
            'sent_at' => now(),
        ];
    }

    public function fromMe(): static
    {
        return $this->state(fn (array $attributes): array => [
            'from_me' => true,
            'sender_jid' => null,
        ]);
    }

    /**
     * Image message: only metadata is stored, never the bytes (RF-36).
     */
    public function image(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'image',
            'text' => null,
            'media' => [
                'mimetype' => 'image/jpeg',
                'size' => 20480,
                'caption' => fake()->sentence(),
            ],
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
