<?php

namespace App\Http\Resources;

use App\Models\Task;
use App\Support\UntrustedContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A background task as seen by its owner (US-10.2): status, agent, progress,
 * the current step and the result when the task finished. Voice tasks also
 * carry the model, the linked visualization and, once terminal, the
 * `voice_summary` narrated by the Realtime session (CT-04, RF-13).
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * Maximum characters of the result narrated by voice (CT-04).
     */
    public const int VOICE_SUMMARY_LIMIT = 2000;

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
            'model' => $this->whenLoaded('model', fn (): ?array => $this->model === null ? null : [
                'name' => $this->model->name,
            ]),
            'visualization_id' => $this->visualization_id,
            'progress' => $this->progress,
            'current_step' => $this->whenLoaded('steps', fn (): ?array => $this->currentStep()),
            'steps' => $this->whenLoaded('steps', fn () => TaskStepResource::collection($this->steps)),
            'logs' => $this->whenLoaded('logs', fn () => TaskLogResource::collection($this->logs)->response()->getData(true)),
            'result' => $this->result,
            'result_type' => $this->whenNotNull($this->resultType?->slug),
            'error' => $this->error,
            'voice_summary' => $this->voiceSummary(),
            'pending_approvals' => $this->whenLoaded('approvals', fn () => ApprovalResource::collection($this->approvals)),
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Result (or error) of a terminal voice task, limited and wrapped as
     * untrusted content for the Realtime session to narrate (RF-13); null
     * for text tasks and while the task is still running.
     */
    private function voiceSummary(): ?string
    {
        if ($this->voice_session_id === null || ! $this->isTerminal()) {
            return null;
        }

        $content = match (true) {
            is_string($this->result['text'] ?? null) => $this->result['text'],
            filled($this->result) => (string) json_encode($this->result, JSON_UNESCAPED_UNICODE),
            filled($this->error) => $this->error,
            default => __('voice.task_cancelled_summary'),
        };

        return UntrustedContent::wrap(Str::limit($content, self::VOICE_SUMMARY_LIMIT), 'task');
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
