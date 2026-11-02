<?php

namespace App\Models;

use Database\Factories\TaskStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['task_id', 'task_status_id', 'position', 'name', 'output', 'error', 'started_at', 'finished_at'])]
class TaskStep extends Model
{
    /** @use HasFactory<TaskStepFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<TaskStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'task_status_id');
    }

    /** @return HasMany<TaskLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(TaskLog::class);
    }

    /** @return HasMany<ToolExecution, $this> */
    public function toolExecutions(): HasMany
    {
        return $this->hasMany(ToolExecution::class);
    }
}
