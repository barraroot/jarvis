<?php

namespace App\Http\Resources;

use App\Models\TaskLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One leveled log line of a background task (US-10.2).
 *
 * @mixin TaskLog
 */
class TaskLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level' => $this->level->slug,
            'message' => $this->message,
            'context' => $this->context,
            'task_step_id' => $this->task_step_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
