<?php

namespace App\Jobs;

use App\Ai\Agents\MemoryHypothesisExtractor;
use App\Events\Assistant\AssistantResponseCompleted;
use App\Exceptions\SensitiveMemoryRejectedException;
use App\Models\ExecutionStatus;
use App\Models\MemoryCategory;
use App\Models\MemoryStatus;
use App\Models\Message;
use App\Models\MessageRole;
use App\Models\User;
use App\Services\MemoryService;
use App\Services\ModelSelector;
use App\Services\ProviderRegistry;
use App\Services\UsageRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * After `assistant.response.completed`, asks the default model (structured
 * output) for up to 3 potentially useful facts from the last exchange. Each
 * becomes a `hypothesis` memory — subject to the sensitive-data detector and
 * duplicate dedupe — and the assistant message's `meta.hypotheses` is updated,
 * re-emitting `assistant.response.completed` so the UI renders the
 * confirmation prompt (US-6.2). Hypotheses never enter the context (US-6.3).
 */
class ExtractMemoryHypotheses implements ShouldQueue
{
    use Queueable;

    /**
     * The extraction call also performs an AI call: no retries (no re-billing), 120 s timeout.
     */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly Message $assistantMessage) {}

    public function handle(): void
    {
        $assistantMessage = Message::query()->find($this->assistantMessage->getKey());

        if ($assistantMessage === null || $assistantMessage->execution_status_id !== ExecutionStatus::idFor(ExecutionStatus::COMPLETED)) {
            return;
        }

        $conversation = $assistantMessage->conversation()->withTrashed()->first();

        if ($conversation === null || $conversation->trashed()) {
            return;
        }

        $user = $assistantMessage->user;

        if ($user === null) {
            return;
        }

        $userMessage = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', '<', $assistantMessage->id)
            ->where('message_role_id', MessageRole::idFor(MessageRole::USER))
            ->latest('id')
            ->first();

        if ($userMessage === null) {
            return;
        }

        app(ProviderRegistry::class)->register();

        $model = app(ModelSelector::class)->defaultTextModel();

        $startedAt = microtime(true);

        try {
            $response = (new MemoryHypothesisExtractor)->prompt(
                "Troca mais recente:\n\nUsuário: {$userMessage->content}\n\nAssistente: {$assistantMessage->content}",
                provider: $model->provider->slug,
                model: $model->model_identifier,
            );

            app(UsageRecorder::class)->record(
                $user,
                $model,
                $response->usage->inputTokens,
                $response->usage->outputTokens,
                (int) round((microtime(true) - $startedAt) * 1000),
                ExecutionStatus::COMPLETED,
                message: $assistantMessage,
            );

            $hypothesisIds = $this->createHypotheses($user, $userMessage, $response->structured['items'] ?? []);
        } catch (Throwable $exception) {
            // Hypotheses are optional; a failed extraction never blocks the conversation.
            report($exception);

            return;
        }

        if ($hypothesisIds === []) {
            return;
        }

        $assistantMessage->update([
            'meta' => array_merge($assistantMessage->meta ?? [], ['hypotheses' => $hypothesisIds]),
        ]);

        AssistantResponseCompleted::dispatch($user, $assistantMessage->fresh());
    }

    /**
     * @param  array<int, array{content?: mixed}>  $items
     * @return list<int>
     */
    private function createHypotheses(User $user, Message $userMessage, array $items): array
    {
        $memoryService = app(MemoryService::class);
        $ids = [];

        foreach (array_slice($items, 0, 3) as $item) {
            $content = trim((string) ($item['content'] ?? ''));

            if ($content === '' || mb_strlen($content) > 2000) {
                continue;
            }

            try {
                $memory = $memoryService->remember(
                    $user,
                    $content,
                    MemoryCategory::CONTEXT,
                    MemoryStatus::HYPOTHESIS,
                    $userMessage,
                );

                $ids[] = $memory->getKey();
            } catch (SensitiveMemoryRejectedException) {
                // Sensitive data is never stored, not even as a hypothesis (US-6.2).
                continue;
            }
        }

        return $ids;
    }
}
