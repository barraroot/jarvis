<?php

namespace App\Http\Resources;

use App\Models\AgentExecution;
use App\Models\Approval;
use App\Models\ToolExecution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of the operator's activity timeline (US-12.4): an agent
 * execution, a tool execution or an approval, normalised to
 * `{type, id, occurred_at, status, title, conversation_id, task_id, details}`
 * so the SPA can render a card per type and link to the conversation/task.
 *
 * @mixin AgentExecution|ToolExecution|Approval
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return match (true) {
            $this->resource instanceof AgentExecution => $this->agentExecution($this->resource),
            $this->resource instanceof ToolExecution => $this->toolExecution($this->resource),
            $this->resource instanceof Approval => $this->approval($this->resource),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function agentExecution(AgentExecution $execution): array
    {
        return [
            'type' => 'agent',
            'id' => $execution->id,
            'occurred_at' => ($execution->started_at ?? $execution->created_at)?->toIso8601String(),
            'status' => $execution->status->slug,
            'title' => $execution->agent->name,
            'conversation_id' => $execution->conversation_id,
            'task_id' => $execution->task_id,
            'details' => [
                'agent_slug' => $execution->agent->slug,
                'depth' => $execution->depth,
                'tool_call_count' => $execution->tool_call_count,
                'task_title' => $execution->task?->title,
                'error' => $execution->error,
                'finished_at' => $execution->finished_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolExecution(ToolExecution $execution): array
    {
        return [
            'type' => 'tool',
            'id' => $execution->id,
            'occurred_at' => ($execution->started_at ?? $execution->created_at)?->toIso8601String(),
            'status' => $execution->status->slug,
            'title' => $execution->tool->name,
            'conversation_id' => $execution->agentExecution?->conversation_id,
            'task_id' => $execution->agentExecution?->task_id ?? $execution->taskStep?->task_id,
            'details' => [
                'identifier' => $execution->tool->identifier,
                'duration_ms' => $execution->duration_ms,
                'error' => $execution->error,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approval(Approval $approval): array
    {
        return [
            'type' => 'approval',
            'id' => $approval->id,
            'occurred_at' => ($approval->decided_at ?? $approval->requested_at)?->toIso8601String(),
            'status' => $approval->status->slug,
            'title' => $approval->tool->name,
            'conversation_id' => $approval->agentExecution?->conversation_id ?? $approval->task?->conversation_id,
            'task_id' => $approval->task_id,
            'details' => [
                'identifier' => $approval->tool->identifier,
                'requested_at' => $approval->requested_at?->toIso8601String(),
                'decided_at' => $approval->decided_at?->toIso8601String(),
                'decision_reason' => $approval->decision_reason,
            ],
        ];
    }
}
