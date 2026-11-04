<?php

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VoiceSession>
 */
class VoiceSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'conversation_id' => fn (array $attributes): int => Conversation::factory()->create(['user_id' => $attributes['user_id']])->id,
            'ai_model_id' => AiModel::factory()->realtime(),
            'total_tokens' => 0,
            'last_issued_at' => now(),
            'last_activity_at' => now(),
        ];
    }

    /**
     * Session idle beyond the active TTL (D11: 30 minutes).
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_issued_at' => now()->subMinutes(31),
            'last_activity_at' => now()->subMinutes(31),
        ]);
    }
}
