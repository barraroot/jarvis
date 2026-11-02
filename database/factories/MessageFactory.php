<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\MessageRole;
use App\Models\MessageSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Defaults to a completed text message from the user, owned by the conversation's user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => fn (array $attributes): int => Conversation::query()->findOrFail($attributes['conversation_id'])->user_id,
            'message_role_id' => MessageRole::idFor(MessageRole::USER),
            'message_source_id' => MessageSource::idFor(MessageSource::TEXT),
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'agent_id' => null,
            'ai_model_id' => null,
            'content' => fake()->sentence(),
            'result_type_id' => null,
            'payload' => null,
            'suggested_actions' => null,
            'meta' => null,
        ];
    }

    public function fromUser(): static
    {
        return $this->state(fn (array $attributes): array => [
            'message_role_id' => MessageRole::idFor(MessageRole::USER),
            'message_source_id' => $attributes['message_source_id'] ?? MessageSource::idFor(MessageSource::TEXT),
        ]);
    }

    public function fromAssistant(): static
    {
        return $this->state(fn (array $attributes): array => [
            'message_role_id' => MessageRole::idFor(MessageRole::ASSISTANT),
            'message_source_id' => null,
        ]);
    }

    public function voice(): static
    {
        return $this->state(fn (array $attributes): array => [
            'message_source_id' => MessageSource::idFor(MessageSource::VOICE),
        ]);
    }

    /**
     * An assistant reply still being streamed.
     */
    public function streaming(): static
    {
        return $this->fromAssistant()->state(fn (array $attributes): array => [
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::RUNNING),
            'content' => '',
        ]);
    }

    public function failed(): static
    {
        return $this->fromAssistant()->state(fn (array $attributes): array => [
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::FAILED),
        ]);
    }
}
