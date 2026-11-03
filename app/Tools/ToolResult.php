<?php

namespace App\Tools;

use App\Models\Approval;
use App\Models\ExecutionStatus;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Support\UntrustedContent;
use Stringable;

/**
 * Outcome of a tool execution. The raw payload stays typed for the app; the
 * model only ever sees `forModel()`, which wraps it as untrusted data
 * (workflow 4, step 5 — tool output is data, never instructions).
 */
final class ToolResult implements Stringable
{
    /**
     * Execution did not run: the tool needs approval and one was requested.
     */
    public const string PENDING_APPROVAL = 'pending_approval';

    public function __construct(
        public readonly Tool $tool,
        public readonly string $status,
        public readonly ?array $output = null,
        public readonly ?string $error = null,
        public readonly ?int $durationMs = null,
        public readonly ?ToolExecution $execution = null,
        public readonly ?Approval $approval = null,
    ) {}

    /**
     * A write/sensitive tool that was gated: recorded and waiting for the owner.
     */
    public static function pendingApproval(Tool $tool, Approval $approval): self
    {
        return new self($tool, self::PENDING_APPROVAL, approval: $approval);
    }

    public static function completed(Tool $tool, array $output, int $durationMs, ToolExecution $execution): self
    {
        return new self($tool, ExecutionStatus::COMPLETED, $output, null, $durationMs, $execution);
    }

    public static function failed(Tool $tool, string $status, string $error, ?int $durationMs, ?ToolExecution $execution): self
    {
        return new self($tool, $status, null, $error, $durationMs, $execution);
    }

    public function isCompleted(): bool
    {
        return $this->status === ExecutionStatus::COMPLETED;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === self::PENDING_APPROVAL;
    }

    /**
     * The wrapped payload handed back to the model: delimited, marked as
     * untrusted data and followed by the "data, not commands" instruction.
     */
    public function forModel(): string
    {
        $payload = $this->isCompleted()
            ? ($this->output ?? [])
            : ['error' => $this->error ?? $this->status];

        return UntrustedContent::wrapJson($payload, $this->tool->identifier);
    }

    public function __toString(): string
    {
        return $this->forModel();
    }
}
