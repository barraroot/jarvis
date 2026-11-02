<?php

namespace App\Services;

use App\Exceptions\AllProvidersFailedException;
use App\Models\AiModel;
use App\Models\ExecutionStatus;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Messages\Message as AiMessage;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Streams a text completion through the fallback chain (US-3.4), recording
 * usage for every attempt and enforcing the user's monthly cost limit.
 */
class BrainClient
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly UsageRecorder $usageRecorder,
        private readonly CostLimitGuard $costLimitGuard,
    ) {}

    /**
     * @param  callable(string): void  $onChunk  Invoked with every text chunk, in order.
     *
     * @throws AllProvidersFailedException
     */
    public function stream(BrainRequest $request, callable $onChunk): BrainResponse
    {
        $this->registry->register();

        $chain = app(FallbackChain::class)->for($request->primaryModel);

        if ($chain === []) {
            throw new AllProvidersFailedException([]);
        }

        $attemptedModels = [];
        $lastException = null;

        foreach ($chain as $model) {
            $attemptedModels[] = $model->model_identifier;

            $this->costLimitGuard->assertWithinLimit($request->user);

            $startedAt = microtime(true);

            try {
                $streamed = $this->streamWithModel($request, $model, $onChunk);

                $latencyMs = $this->elapsedMs($startedAt);
                $usage = $streamed->usage ?? new TextUsage;

                $this->usageRecorder->record(
                    $request->user,
                    $model,
                    $usage->inputTokens,
                    $usage->outputTokens,
                    $latencyMs,
                    ExecutionStatus::COMPLETED,
                    $request->agentExecution,
                    $request->message,
                );

                return new BrainResponse(
                    $streamed->text ?? '',
                    $model,
                    $usage->inputTokens,
                    $usage->outputTokens,
                    $latencyMs,
                );
            } catch (Throwable $exception) {
                $lastException = $exception;

                $this->usageRecorder->record(
                    $request->user,
                    $model,
                    0,
                    0,
                    $this->elapsedMs($startedAt),
                    $this->failureStatus($exception),
                    $request->agentExecution,
                    $request->message,
                );
            }
        }

        throw new AllProvidersFailedException($attemptedModels, $lastException);
    }

    private function streamWithModel(BrainRequest $request, AiModel $model, callable $onChunk): StreamableAgentResponse
    {
        $messages = array_map(
            fn (array $message): AiMessage => new AiMessage($message['role'], $message['content']),
            $request->messages,
        );

        $streamable = agent(
            instructions: $request->instructions ?? 'You are JARVIS, a helpful personal AI assistant.',
            messages: $messages,
            tools: $request->tools,
        )->stream(
            $request->prompt,
            provider: $model->provider->slug,
            model: $model->model_identifier,
        );

        foreach ($streamable as $event) {
            if ($event instanceof TextDelta) {
                $onChunk($event->delta);
            }
        }

        return $streamable;
    }

    private function failureStatus(Throwable $exception): string
    {
        if ($exception instanceof RateLimitedException) {
            return ExecutionStatus::RATE_LIMITED;
        }

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
