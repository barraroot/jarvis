<?php

namespace App\Models;

use Database\Factories\TaskLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'task_step_id', 'log_level_id', 'message', 'context', 'created_at'])]
class TaskLog extends Model
{
    /** @use HasFactory<TaskLogFactory> */
    use HasFactory;

    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<TaskStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(TaskStep::class, 'task_step_id');
    }

    /** @return BelongsTo<LogLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(LogLevel::class, 'log_level_id');
    }
}
