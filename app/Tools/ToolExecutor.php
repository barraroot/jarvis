<?php

namespace App\Tools;

use App\Events\Tool\ToolCompleted;
use App\Events\Tool\ToolFailed;
use App\Events\Tool\ToolStarted;
use App\Models\Agent;
use App\Models\ApprovalStatus;
use App\Models\AuditAction;
use App\Models\ExecutionStatus;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\ToolType;
use App\Services\ApprovalService;
use App\Support\AuditLogger;
use App\Support\JsonSchemaValidator;
use App\Support\Settings;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Exceptions\ToolInputInvalidException;
use App\Tools\Exceptions\ToolNotAuthorizedException;
use App\Tools\Exceptions\ToolOutputSchemaException;
use App\Tools\Exceptions\ToolTimeoutException;
use RuntimeException;
use Throwable;

/**
 * Executes catalogue tools safely (US-8.2): authorization, JSON Schema
 * validation, approval gating, timeout, `tool_executions` recording and
 * `tool.started|completed|failed` events.
 */
class ToolExecutor
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly JsonSchemaValidator $schemas,
        private readonly AuditLogger $audit,
        private readonly Settings $settings,
    ) {}

    /**
     * Execute a tool with validated input and full recording. Write/sensitive
     * tools without a carried approval open one and do NOT run.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ToolNotAuthorizedException
     * @throws ToolInputInvalidException
     */
    public function execute(Tool $tool, array $input, ToolContext $context): ToolResult
    {
        $this->assertAuthorized($tool, $context);

        if ($context->approval !== null) {
            $this->ensureApprovalExecutable($context);
        } elseif ($tool->requiresApproval()) {
            return ToolResult::pendingApproval($tool, $this->approvals->request($tool, $input, $context));
        }

        $errors = $this->schemas->validate($tool->input_schema, $input);

        if ($errors !== []) {
            $execution = $this->record($tool, $context, ExecutionStatus::FAILED, [
                'input' => $input,
                'error' => 'Invalid input: '.implode('; ', $errors),
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            throw new ToolInputInvalidException($tool->identifier, $errors);
        }

        $execution = $this->record($tool, $context, ExecutionStatus::RUNNING, [
            'input' => $input,
            'started_at' => now(),
        ]);

        // The enriched `tool.*` payload (CT-03) reads these relations.
        $tool->loadMissing(['mcpToolDefinition.server']);
        $execution->loadMissing(['agentExecution', 'taskStep']);

        ToolStarted::dispatch($context->user, $tool, $execution);

        $startedAt = microtime(true);

        try {
            $output = $this->run($tool, $input, $context);

            $durationMs = $this->elapsedMs($startedAt);

            $outputErrors = $this->schemas->validate($tool->output_schema, $output);

            if ($outputErrors !== []) {
                throw new ToolOutputSchemaException($outputErrors);
            }

            $execution->update([
                'output' => $output,
                'duration_ms' => $durationMs,
                'finished_at' => now(),
                'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            ]);

            ToolCompleted::dispatch($context->user, $tool, $execution);

            $this->auditWriteExecution($tool, $context, $execution);

            return ToolResult::completed($tool, $output, $durationMs, $execution);
        } catch (Throwable $exception) {
            $status = $exception instanceof ToolTimeoutException
                ? ExecutionStatus::TIMED_OUT
                : ExecutionStatus::FAILED;

            $execution->update([
                'error' => $exception->getMessage(),
                'duration_ms' => $this->elapsedMs($startedAt),
                'finished_at' => now(),
                'execution_status_id' => ExecutionStatus::idFor($status),
            ]);

            ToolFailed::dispatch($context->user, $tool, $execution);

            return ToolResult::failed($tool, $status, $exception->getMessage(), $execution->duration_ms, $execution);
        }
    }

    /**
     * Inactive tools never run; when an agent execution carries the call, the
     * agent must have the tool among its authorized ones (US-7.3).
     */
    private function assertAuthorized(Tool $tool, ToolContext $context): void
    {
        if (! $tool->is_active) {
            throw new ToolNotAuthorizedException("Tool [{$tool->identifier}] is inactive.");
        }

        if ($context->agentExecution !== null) {
            $authorized = Agent::query()
                ->whereKey($context->agentExecution->agent_id)
                ->whereHas('tools', fn ($query) => $query->whereKey($tool->id))
                ->exists();

            if (! $authorized) {
                throw new ToolNotAuthorizedException("Tool [{$tool->identifier}] is not authorized for this agent.");
            }
        }
    }

    /**
     * An approval carried into the executor must still allow execution: a
     * pending one is marked approved (SDK path already got the decision); an
     * expired or denied one blocks the run.
     */
    private function ensureApprovalExecutable(ToolContext $context): void
    {
        $approval = $context->approval->fresh();

        if ($approval === null) {
            throw new ToolNotAuthorizedException('The approval no longer exists.');
        }

        if ($approval->isPending()) {
            $this->approvals->markApproved($approval, $context->user);

            return;
        }

        if ($approval->isExpired() || $approval->status->slug !== ApprovalStatus::APPROVED) {
            throw new ToolNotAuthorizedException('The approval no longer allows execution.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(Tool $tool, ToolContext $context, string $statusSlug, array $attributes): ToolExecution
    {
        return ToolExecution::query()->create([
            'tool_id' => $tool->id,
            'user_id' => $context->user->id,
            'execution_status_id' => ExecutionStatus::idFor($statusSlug),
            'agent_execution_id' => $context->agentExecution?->id,
            'task_step_id' => $context->taskStep?->id,
            'approval_id' => $context->approval?->id,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(Tool $tool, array $input, ToolContext $context): array
    {
        return match ($tool->type->slug) {
            ToolType::INTERNAL => $this->runInternal($tool, $input, $context),
            ToolType::REST, ToolType::WEBHOOK => app(HttpToolHandler::class)->handle($tool, $input, $context),
            ToolType::MCP => app(McpToolHandler::class)->handle($tool, $input, $context),
            default => throw new RuntimeException("Tool type [{$tool->type->slug}] is not executable locally."),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function runInternal(Tool $tool, array $input, ToolContext $context): array
    {
        $handler = filled($tool->handler_class)
            ? app($tool->handler_class)
            : app(InternalToolRegistry::class)->all()[$tool->identifier] ?? null;

        if (! $handler instanceof JarvisTool) {
            throw new RuntimeException("Tool [{$tool->identifier}] has no resolvable internal handler.");
        }

        $timeoutSeconds = $tool->timeout_seconds > 0
            ? $tool->timeout_seconds
            : $this->settings->toolTimeoutSeconds();

        return $this->runWithTimeout(
            fn (): array => $handler->handle($input, $context),
            $timeoutSeconds,
        );
    }

    /**
     * Enforce the tool timeout. With pcntl (CLI/queue), a SIGALRM handler
     * raises `ToolTimeoutException`; otherwise `set_time_limit` guards the
     * call as a last resort.
     *
     * @return array<string, mixed>
     */
    private function runWithTimeout(callable $callable, int $timeoutSeconds): array
    {
        if (function_exists('pcntl_alarm')) {
            return $this->runWithAlarm($callable, $timeoutSeconds);
        }

        $previous = (int) ini_get('max_execution_time');

        set_time_limit(max(1, $timeoutSeconds));

        try {
            return $callable();
        } finally {
            set_time_limit($previous);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runWithAlarm(callable $callable, int $timeoutSeconds): array
    {
        $previousHandler = pcntl_signal_get_handler(SIGALRM);

        pcntl_async_signals(true);

        pcntl_signal(SIGALRM, function (): never {
            throw new ToolTimeoutException('Tool execution timed out.');
        });

        pcntl_alarm(max(1, $timeoutSeconds));

        try {
            return $callable();
        } finally {
            pcntl_alarm(0);

            if (is_callable($previousHandler)) {
                pcntl_signal(SIGALRM, $previousHandler);
            }
        }
    }

    /**
     * Write tools are audited (US-12.3, US-8.3) with the approval that
     * authorized the run and who granted it; secrets and payloads never
     * enter the audit trail.
     */
    private function auditWriteExecution(Tool $tool, ToolContext $context, ToolExecution $execution): void
    {
        if (! $tool->is_write) {
            return;
        }

        $approval = $context->approval?->fresh();

        $this->audit->log(AuditAction::TOOL_EXECUTED, $context->user, $execution, [
            'tool' => $tool->identifier,
            'execution_id' => $execution->id,
            'duration_ms' => $execution->duration_ms,
            'status' => ExecutionStatus::COMPLETED,
            'approval_id' => $approval?->id,
            'approved_by' => $approval?->decided_by_user_id,
        ]);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
