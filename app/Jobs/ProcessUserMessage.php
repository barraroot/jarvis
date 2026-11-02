<?php

namespace App\Jobs;

use App\Events\Assistant\AssistantResponseChunk;
use App\Events\Assistant\AssistantResponseCompleted;
use App\Events\Assistant\AssistantStateChanged;
use App\Events\Assistant\AssistantThinking;
use App\Exceptions\AllProvidersFailedException;
use App\Exceptions\UsageLimitExceededException;
use App\Models\Conversation;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\MessageRole;
use App\Models\NotificationType;
use App\Models\ResultType;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\BrainClient;
use App\Services\BrainRequest;
use App\Services\ContextBuilder;
use App\Services\MemoryContextSource;
use App\Services\MessageRouter;
use App\Services\NotificationService;
use App\Services\RoutedMessage;
use App\Services\RunOptions;
use App\Tools\RememberMemoryTool;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Turns a user message into a streamed assistant reply (workflow 2, steps 3–5).
 *
 * The AI call itself is never retried (each attempt bills usage) and may stream
 * for up to 120 seconds. Routing always answers directly in this phase; the
 * intent router (Phase 12) plugs in behind {@see MessageRouter}.
 */
class ProcessUserMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly Message $userMessage) {}

    public function handle(): void
    {
        $conversation = $this->userMessage->conversation()->withTrashed()->first();

        if ($conversation === null || $conversation->trashed()) {
            return;
        }

        $user = $conversation->user;

        if ($user === null) {
            return;
        }

        $assistantMessage = $this->createAssistantMessage($conversation, $user);

        AssistantStateChanged::dispatch($user, AssistantStateChanged::THINKING, $conversation->id);
        AssistantThinking::dispatch($user, $assistantMessage->id);

        try {
            $this->streamReply($conversation, $user, $assistantMessage);
        } catch (AllProvidersFailedException $exception) {
            $this->failReply($assistantMessage, $user, $conversation, 'all_providers_failed', $exception);
        } catch (UsageLimitExceededException $exception) {
            $this->failReply($assistantMessage, $user, $conversation, 'usage_limit_exceeded', $exception);
        }
    }

    /**
     * The assistant reply starts `pending` and becomes `running` on its first chunk.
     */
    private function createAssistantMessage(Conversation $conversation, User $user): Message
    {
        return Message::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'message_role_id' => MessageRole::idFor(MessageRole::ASSISTANT),
            'message_source_id' => null,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::PENDING),
            'content' => '',
        ]);
    }

    private function streamReply(Conversation $conversation, User $user, Message $assistantMessage): void
    {
        $routed = app(MessageRouter::class)->route($this->userMessage);

        if ($routed->agent !== null) {
            $this->runAgentReply($conversation, $user, $assistantMessage, $routed);

            return;
        }

        $history = app(ContextBuilder::class)->build(
            $conversation,
            $routed->model,
            excluding: $this->userMessage,
            sources: [
                app(MemoryContextSource::class, ['user' => $user, 'query' => $this->userMessage->content]),
            ],
        );

        $content = '';
        $chunkIndex = 0;

        $response = app(BrainClient::class)->stream(
            new BrainRequest(
                user: $user,
                primaryModel: $routed->model,
                prompt: $this->userMessage->content,
                messages: $history,
                instructions: $routed->instructions,
                message: $assistantMessage,
                tools: [
                    app(RememberMemoryTool::class, ['user' => $user, 'source' => $this->userMessage]),
                ],
            ),
            function (string $delta) use ($assistantMessage, $user, $conversation, &$content, &$chunkIndex): void {
                if ($chunkIndex === 0 && $assistantMessage->execution_status_id === ExecutionStatus::idFor(ExecutionStatus::PENDING)) {
                    $assistantMessage->update([
                        'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::RUNNING),
                    ]);
                }

                AssistantResponseChunk::dispatch($user, $assistantMessage->id, $delta, $chunkIndex, $conversation->id);

                $content .= $delta;
                $chunkIndex++;
            },
        );

        $result = $response->structuredResult ?? $this->textResult($content);

        $assistantMessage->update([
            'content' => $content,
            'payload' => $result['payload'],
            'result_type_id' => ResultType::idFor($result['type']),
            'suggested_actions' => $result['payload']['suggested_actions'] ?? null,
            'ai_model_id' => $response->model->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
        ]);

        AssistantResponseCompleted::dispatch($user, $assistantMessage->fresh());
        AssistantStateChanged::dispatch($user, AssistantStateChanged::IDLE, $conversation->id);

        // After the completed event, the low-priority job proposes memory
        // hypotheses for this exchange (US-6.2).
        ExtractMemoryHypotheses::dispatch($assistantMessage)->onConnection('redis')->onQueue('low');

        $conversation->touchActivity();

        $this->dispatchTitleJob($conversation);
    }

    /**
     * A routed agent run (Phase 12): the agent streams into the assistant
     * message, which is persisted from the execution output afterwards.
     */
    private function runAgentReply(Conversation $conversation, User $user, Message $assistantMessage, RoutedMessage $routed): void
    {
        $prompt = $routed->prompt ?? $this->userMessage->content;

        $execution = app(AgentRunner::class)->run(
            $routed->agent,
            $user,
            $prompt,
            new RunOptions(
                conversation: $conversation,
                message: $assistantMessage,
                contextSources: [
                    app(MemoryContextSource::class, ['user' => $user, 'query' => $prompt]),
                ],
            ),
        );

        if ($execution->execution_status_id !== ExecutionStatus::idFor(ExecutionStatus::COMPLETED)) {
            $this->failReply($assistantMessage, $user, $conversation, 'agent_failed', new \RuntimeException((string) ($execution->error ?? '')));

            return;
        }

        $content = (string) ($execution->meta['output'] ?? '');
        $result = $execution->meta['result'] ?? $this->textResult($content);

        $assistantMessage->update([
            'content' => $content,
            'payload' => $result['payload'],
            'result_type_id' => ResultType::idFor($result['type']),
            'suggested_actions' => $result['payload']['suggested_actions'] ?? null,
            'ai_model_id' => $routed->agent->effectiveModel()->id,
            'agent_id' => $routed->agent->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
        ]);

        AssistantResponseCompleted::dispatch($user, $assistantMessage->fresh());
        AssistantStateChanged::dispatch($user, AssistantStateChanged::IDLE, $conversation->id);

        ExtractMemoryHypotheses::dispatch($assistantMessage)->onConnection('redis')->onQueue('low');

        $conversation->touchActivity();

        $this->dispatchTitleJob($conversation);
    }

    /**
     * Canonical plain-text result for replies without a structured block.
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function textResult(string $content): array
    {
        return ['type' => ResultType::TEXT, 'payload' => ['type' => ResultType::TEXT, 'text' => $content]];
    }

    /**
     * A failed reply still surfaces to the operator as a friendly assistant message:
     * state goes ERROR then IDLE, and the completed event carries the i18n text.
     */
    private function failReply(Message $assistantMessage, User $user, Conversation $conversation, string $errorKey, Throwable $exception): void
    {
        $assistantMessage->update([
            'content' => __("assistant.errors.{$errorKey}"),
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::FAILED),
            'meta' => [
                'error' => $errorKey,
                'exception' => class_basename($exception),
            ],
        ]);

        if ($errorKey === 'usage_limit_exceeded') {
            app(NotificationService::class)->notify(
                $user,
                NotificationType::USAGE_LIMIT_REACHED,
                'Limite de consumo atingido',
                'O limite mensal de custo foi atingido; novas chamadas foram bloqueadas.',
                ['conversation_id' => $conversation->id],
            );
        }

        AssistantStateChanged::dispatch($user, AssistantStateChanged::ERROR, $conversation->id);
        AssistantStateChanged::dispatch($user, AssistantStateChanged::IDLE, $conversation->id);
        AssistantResponseCompleted::dispatch($user, $assistantMessage->fresh());
    }

    /**
     * After the first successful reply, a provisional title is replaced (US-2.6).
     */
    private function dispatchTitleJob(Conversation $conversation): void
    {
        if (! $conversation->hasProvisionalTitle() || $conversation->titleWasEdited()) {
            return;
        }

        GenerateConversationTitle::dispatch($conversation)->onConnection('redis');
    }
}
