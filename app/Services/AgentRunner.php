<?php

namespace App\Services;

use App\Events\Agent\Completed;
use App\Events\Agent\Failed;
use App\Events\Agent\Progress;
use App\Events\Agent\Started;
use App\Events\Assistant\AssistantResponseChunk;
use App\Exceptions\AgentUnavailableException;
use App\Exceptions\ToolCallLimitExceededException;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AiModel;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\User;
use App\Support\Settings;
use App\Tools\ToolContext;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Throwable;

/**
 * Executes a catalogue agent through `laravel/ai` (US-7.3, US-7.4): records
 * the `agent_execution` (status, depth, parent), streams the reply into the
 * conversation, enforces the tool-call limit and the timeout, and broadcasts
 * `agent.*` events. Runtime failures mark the execution `failed`/`timed_out`
 * and are returned — never thrown — so delegating parents receive them as
 * tool results. Only inactive agents throw {@see AgentUnavailableException}.
 */
class AgentRunner
{
    public function __construct(
        private readonly AgentRuntimeFactory $factory,
        private readonly ProviderRegistry $providerRegistry,
        private readonly UsageRecorder $usageRecorder,
        private readonly Settings $settings,
        private readonly StructuredResultExtractor $structuredResults,
    ) {}

    public function run(Agent $agent, User $user, string $prompt, RunOptions $options): AgentExecution
    {
        if (! $agent->is_active) {
            throw new AgentUnavailableException($agent);
        }

        $execution = $this->createExecution($agent, $user, $options);

        Log::withContext(['agent_execution_id' => $execution->id]);

        try {
            return $this->execute($agent, $user, $prompt, $options, $execution);
        } finally {
            // Nested (delegated) runs hand the context back to their parent.
            if ($options->parent !== null) {
                Log::withContext(['agent_execution_id' => $options->parent->id]);
            } else {
                Log::withoutContext(['agent_execution_id']);
            }
        }
    }

    private function execute(Agent $agent, User $user, string $prompt, RunOptions $options, AgentExecution $execution): AgentExecution
    {
        Started::dispatch($user, $execution);

        $startedAt = microtime(true);

        try {
            $model = $agent->effectiveModel();
            $this->providerRegistry->register();
        } catch (Throwable $exception) {
            return $this->finishAs($execution, $user, ExecutionStatus::FAILED, $exception, $startedAt, null, '', $options);
        }

        $history = $this->messagesFor($options, $model);
        $runtimeAgent = $this->factory->make($agent, $this->contextFor($user, $execution, $options), $history);

        $limit = $this->factory->toolCallLimit($agent);

        $content = '';
        $chunkIndex = 0;
        $toolCalls = 0;

        try {
            $stream = $runtimeAgent->stream(
                $prompt,
                provider: $model->provider->slug,
                model: $model->model_identifier,
                timeout: $agent->timeout_seconds,
            );

            foreach ($stream as $event) {
                if ($event instanceof ToolCall) {
                    $toolCalls++;

                    $execution->update(['tool_call_count' => $toolCalls]);

                    if ($toolCalls > $limit) {
                        return $this->finishAs(
                            $execution,
                            $user,
                            ExecutionStatus::FAILED,
                            new ToolCallLimitExceededException($agent, $limit),
                            $startedAt,
                            $model,
                            $content,
                            $options,
                        );
                    }

                    Progress::dispatch($user, $execution->fresh(), 'Chamando ferramenta '.$event->toolCall->name);

                    continue;
                }

                if ($event instanceof TextDelta) {
                    if ($chunkIndex === 0 && $options->message !== null) {
                        $this->markMessageRunning($options->message);
                    }

                    $this->streamChunk($user, $options, $delta = $event->delta, $chunkIndex);

                    $content .= $delta;
                    $chunkIndex++;
                }
            }

            $usage = $stream->usage ?? new TextUsage;

            $meta = ['output' => $content];

            $structured = $this->structuredResults->extract(
                $user,
                $model,
                $content,
                $history,
                $toolCalls > 0,
            );

            if ($structured !== null) {
                $meta['result'] = $structured;
            }

            $execution->update([
                'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
                'finished_at' => now(),
                'meta' => $meta,
            ]);

            $this->usageRecorder->record(
                $user,
                $model,
                $usage->inputTokens,
                $usage->outputTokens,
                $this->elapsedMs($startedAt),
                ExecutionStatus::COMPLETED,
                $execution,
                $options->message,
            );

            Completed::dispatch($user, $execution->fresh());

            return $execution;
        } catch (Throwable $exception) {
            $status = $this->failureStatus($exception);

            return $this->finishAs($execution, $user, $status, $exception, $startedAt, $model, $content, $options);
        }
    }

    private function createExecution(Agent $agent, User $user, RunOptions $options): AgentExecution
    {
        return AgentExecution::query()->create([
            'user_id' => $user->id,
            'agent_id' => $agent->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::RUNNING),
            'parent_agent_execution_id' => $options->parent?->id,
            'depth' => $options->resolvedDepth(),
            'task_id' => $options->task?->id,
            'conversation_id' => $options->conversation?->id ?? $options->message?->conversation_id,
            'message_id' => $options->message?->id,
            'tool_call_count' => 0,
            'started_at' => now(),
        ]);
    }

    /**
     * The Tool Engine context of this run: tools execute under the run's own
     * `agent_execution`, so `tool_executions` link to it (US-8.2).
     */
    private function contextFor(User $user, AgentExecution $execution, RunOptions $options): ToolContext
    {
        return new ToolContext(
            user: $user,
            task: $options->task,
            agentExecution: $execution,
        );
    }

    /**
     * Short-term context (last completed messages) plus the run's context
     * sources (memories). Delegated runs without a conversation get the
     * sources alone.
     *
     * @return list<array{role: string, content: string}>
     */
    private function messagesFor(RunOptions $options, AiModel $model): array
    {
        if ($options->conversation !== null) {
            return app(ContextBuilder::class)->build(
                $options->conversation,
                $model,
                excluding: $options->message,
                sources: $options->contextSources,
            );
        }

        return array_merge([], ...array_map(
            static fn (ContextSource $source): array => $source->contextMessages(),
            $options->contextSources,
        ));
    }

    private function markMessageRunning(Message $message): void
    {
        if ($message->execution_status_id === ExecutionStatus::idFor(ExecutionStatus::PENDING)) {
            $message->update(['execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::RUNNING)]);
        }
    }

    private function streamChunk(User $user, RunOptions $options, string $delta, int $index): void
    {
        if ($options->message === null) {
            return;
        }

        AssistantResponseChunk::dispatch(
            $user,
            $options->message->id,
            $delta,
            $index,
            $options->conversation?->id ?? $options->message->conversation_id,
        );
    }

    private function finishAs(
        AgentExecution $execution,
        User $user,
        string $statusSlug,
        Throwable $exception,
        float $startedAt,
        ?AiModel $model,
        string $content,
        RunOptions $options,
    ): AgentExecution {
        $execution->update([
            'execution_status_id' => ExecutionStatus::idFor($statusSlug),
            'finished_at' => now(),
            'error' => $exception->getMessage(),
            'meta' => ['output' => $content, 'error' => $exception->getMessage()],
        ]);

        if ($model !== null) {
            $this->usageRecorder->record(
                $user,
                $model,
                0,
                0,
                $this->elapsedMs($startedAt),
                $statusSlug,
                $execution,
                $options->message,
            );
        }

        Failed::dispatch($user, $execution->fresh());

        return $execution;
    }

    private function failureStatus(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return ExecutionStatus::TIMED_OUT;
        }

        return ExecutionStatus::FAILED;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
