<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappScheduleStatus;
use App\Models\WhatsappSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappSchedule>
 */
class WhatsappScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $runAt = now()->addDay()->startOfHour();

        return [
            'user_id' => User::factory(),
            'whatsapp_session_id' => fn (array $attributes): int => WhatsappSession::query()->where('user_id', $attributes['user_id'])->value('id')
                ?? WhatsappSession::factory()->create(['user_id' => $attributes['user_id']])->id,
            'approval_id' => null,
            'whatsapp_schedule_status_id' => WhatsappScheduleStatus::idFor(WhatsappScheduleStatus::ACTIVE),
            'to_jid' => '55119'.fake()->numerify('########').'@s.whatsapp.net',
            'kind' => WhatsappSchedule::KIND_TEXT,
            'payload' => ['text' => fake()->sentence()],
            'run_at' => $runAt,
            'recurrence' => null,
            'next_run_at' => $runAt,
            'last_run_at' => null,
            'approval_expires_at' => now()->addDays(90),
        ];
    }

    /**
     * Daily at 09:00 in `America/Sao_Paulo` (RF-30).
     */
    public function recurring(string $cron = '0 9 * * *'): static
    {
        return $this->state(fn (array $attributes): array => [
            'run_at' => null,
            'recurrence' => $cron,
        ]);
    }

    public function paused(): static
    {
        return $this->status(WhatsappScheduleStatus::PAUSED);
    }

    public function cancelled(): static
    {
        return $this->status(WhatsappScheduleStatus::CANCELLED)->state(fn (array $attributes): array => ['next_run_at' => null]);
    }

    public function completed(): static
    {
        return $this->status(WhatsappScheduleStatus::COMPLETED)->state(fn (array $attributes): array => ['next_run_at' => null]);
    }

    public function status(string $statusSlug): static
    {
        return $this->state(fn (array $attributes): array => [
            'whatsapp_schedule_status_id' => WhatsappScheduleStatus::idFor($statusSlug),
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
