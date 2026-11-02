<?php

namespace App\Http\Resources;

use App\Models\AgentExecution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An agent execution as seen by its owner (US-7.4): agent, status, delegation
 * chain position, duration and — when loaded — the nested child executions.
 *
 * @mixin AgentExecution
 */
class AgentExecutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent' => [
                'name' => $this->agent->name,
                'slug' => $this->agent->slug,
            ],
            'status' => $this->status->slug,
            'depth' => $this->depth,
            'parent_id' => $this->parent_agent_execution_id,
            'tool_call_count' => $this->tool_call_count,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_ms' => $this->durationMs(),
            'children' => AgentExecutionResource::collection($this->whenLoaded('children')),
        ];
    }

    private function durationMs(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        $end = $this->finished_at ?? now();

        return max(0, (int) $this->started_at->diffInMilliseconds($end));
    }
}
