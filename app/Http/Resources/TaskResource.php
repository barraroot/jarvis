<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A background task as seen by its owner (US-10.2): status, agent, progress,
 * the current step and the result when the task finished.
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->slug,
            'title' => $this->title,
            'request' => $this->request,
            'retry_of_task_id' => $this->retry_of_task_id,
            'agent' => $this->whenLoaded('agent', fn (): array => [
                'slug' => $this->agent->slug,
                'name' => $this->agent->name,
            ]),
            'progress' => $this->progress,
            'current_step' => $this->whenLoaded('steps', fn (): ?array => $this->currentStep()),
            'steps' => $this->whenLoaded('steps', fn () => TaskStepResource::collection($this->steps)),
            'logs' => $this->whenLoaded('logs', fn () => TaskLogResource::collection($this->logs)->response()->getData(true)),
            'result' => $this->result,
            'result_type' => $this->whenNotNull($this->resultType?->slug),
            'error' => $this->error,
            'pending_approvals' => $this->whenLoaded('approvals', fn () => ApprovalResource::collection($this->approvals)),
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The step the task is currently on: the last unfinished one, or the last
     * step overall when every step finished.
     *
     * @return array{position: int, name: string, status: string}|null
     */
    private function currentStep(): ?array
    {
        $step = $this->steps
            ->last(fn ($step): bool => $step->finished_at === null)
            ?? $this->steps->last();

        if ($step === null) {
            return null;
        }

        return [
            'position' => $step->position,
            'name' => $step->name,
            'status' => $step->status->slug,
        ];
    }
}
