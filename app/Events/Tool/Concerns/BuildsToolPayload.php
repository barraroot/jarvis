<?php

namespace App\Events\Tool\Concerns;

use App\Models\Tool;
use App\Models\ToolExecution;
use App\Support\AuditLogger;
use Illuminate\Support\Str;

/**
 * Shared `tool.*` payload (CT-03): the original `{tool, execution_id, status}`
 * plus the task/agent execution it belongs to, a display name (`servidor ·
 * tool` for MCP), a secret-free input summary and the timestamps. The
 * executor eager loads `mcpToolDefinition.server`, `agentExecution` and
 * `taskStep` before dispatching, so nothing here lazy loads.
 *
 * @property-read Tool $tool
 * @property-read ToolExecution $execution
 */
trait BuildsToolPayload
{
    /**
     * Maximum characters of `input_summary` (CT-03).
     */
    public const int INPUT_SUMMARY_LIMIT = 120;

    /**
     * @return array{tool: string, execution_id: int, status: string, task_id: int|null, agent_execution_id: int|null, display_name: string, input_summary: string, output_bytes: int|null, started_at: string|null, finished_at: string|null}
     */
    protected function toolPayload(string $status, bool $withOutputBytes = false): array
    {
        return [
            'tool' => $this->tool->identifier,
            'execution_id' => $this->execution->id,
            'status' => $status,
            'task_id' => $this->execution->agentExecution?->task_id ?? $this->execution->taskStep?->task_id,
            'agent_execution_id' => $this->execution->agent_execution_id,
            'display_name' => $this->displayName(),
            'input_summary' => $this->inputSummary(),
            'output_bytes' => $withOutputBytes ? $this->outputBytes() : null,
            'started_at' => $this->execution->started_at?->toIso8601String(),
            'finished_at' => $this->execution->finished_at?->toIso8601String(),
        ];
    }

    private function displayName(): string
    {
        $server = $this->tool->mcpToolDefinition?->server;

        if ($server === null) {
            return $this->tool->name;
        }

        return $server->name.' · '.$this->tool->mcpToolDefinition->name;
    }

    private function inputSummary(): string
    {
        $input = AuditLogger::sanitize($this->execution->input ?? []);

        if ($input === []) {
            return '';
        }

        $json = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return Str::limit($json, self::INPUT_SUMMARY_LIMIT - 3);
    }

    private function outputBytes(): ?int
    {
        if ($this->execution->output === null) {
            return null;
        }

        return strlen((string) json_encode($this->execution->output));
    }
}
