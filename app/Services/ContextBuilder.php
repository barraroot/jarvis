<?php

namespace App\Services;

use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\MessageRole;
use App\Support\Settings;

/**
 * Builds the short-term context of an AI call (US-3.2): the last
 * `brain.context_messages` completed user/assistant messages, oldest first,
 * trimmed to a token budget derived from the model's context window. The
 * budget never errors out — oldest messages are dropped instead.
 */
class ContextBuilder
{
    /**
     * Share of the model's context window usable as history.
     */
    public const float TOKEN_BUDGET_RATIO = 0.6;

    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  list<ContextSource>  $sources  Extra context (memories, agent instructions), placed before the history.
     * @return list<array{role: string, content: string}>
     */
    public function build(Conversation $conversation, AiModel $model, ?Message $excluding = null, array $sources = []): array
    {
        $messages = [
            ...$this->messagesFromSources($sources),
            ...$this->history($conversation, $excluding),
        ];

        return $this->trimToTokenBudget($messages, $model);
    }

    /**
     * @param  list<ContextSource>  $sources
     * @return list<array{role: string, content: string}>
     */
    private function messagesFromSources(array $sources): array
    {
        return array_merge([], ...array_map(
            static fn (ContextSource $source): array => $source->contextMessages(),
            $sources,
        ));
    }

    /**
     * Last N completed user/assistant messages of the conversation, oldest first.
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(Conversation $conversation, ?Message $excluding): array
    {
        $limit = max(1, $this->settings->contextMessages());

        return $conversation->messages()
            ->with('role')
            ->whereIn('message_role_id', [
                MessageRole::idFor(MessageRole::USER),
                MessageRole::idFor(MessageRole::ASSISTANT),
            ])
            ->where('execution_status_id', ExecutionStatus::idFor(ExecutionStatus::COMPLETED))
            ->when($excluding !== null, fn ($query) => $query->whereKeyNot($excluding->getKey()))
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(static fn (Message $message): array => [
                'role' => $message->role->slug,
                'content' => $message->content,
            ])
            ->all();
    }

    /**
     * Drop the oldest messages while the estimated token count exceeds the
     * budget; the newest message is always kept. Models without a context
     * window skip the budget entirely.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    private function trimToTokenBudget(array $messages, AiModel $model): array
    {
        if ($messages === [] || $model->context_window === null) {
            return $messages;
        }

        $budget = (int) floor($model->context_window * self::TOKEN_BUDGET_RATIO);

        while (count($messages) > 1 && $this->estimatedTokens($messages) > $budget) {
            array_shift($messages);
        }

        return array_values($messages);
    }

    /**
     * Rough token estimate: 4 characters per token (project-description §4.2).
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function estimatedTokens(array $messages): int
    {
        $characters = array_sum(array_map(
            static fn (array $message): int => strlen((string) ($message['content'] ?? '')),
            $messages,
        ));

        return (int) ceil($characters / 4);
    }
}
