<?php

namespace App\Services;

use App\Exceptions\TaskCancelledException;
use App\Models\LogLevel;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\TaskStatus;
use App\Models\TaskStep;

/**
 * The execution context of a `RunTask` job (US-10.1): the surface through
 * which the agent records its work — sequential steps (`task_steps`), leveled
 * logs (`task_logs`) and monotonic progress (0–100). Bound into the container
 * while the job runs, so the `task.*` internal tools and the runner share the
 * same task instance.
 *
 * On resumed runs (`resumeFromStep`), steps already concluded are returned
 * as-is by name instead of being recreated, so finished work never repeats
 * (US-10.3).
 */
class TaskContext
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly Task $task,
        private readonly int $resumeFromStep = 0,
    ) {}

    public function task(): Task
    {
        return $this->task;
    }

    public function resumeFromStep(): int
    {
        return $this->resumeFromStep;
    }

    /**
     * Open (or reuse) a step. Positions are sequential per task; the
     * cooperative cancellation flag is checked first, so a cancelled task
     * never starts another step.
     *
     * @throws TaskCancelledException
     */
    public function startStep(string $name): TaskStep
    {
        $this->tasks->assertNotCancelled($this->task);

        $existing = $this->task->steps()->where('name', $name)->first();

        if ($existing !== null) {
            return $existing;
        }

        $position = ((int) $this->task->steps()->max('position')) + 1;

        $step = $this->task->steps()->create([
            'task_status_id' => TaskStatus::idFor(TaskStatus::RUNNING),
            'position' => $position,
            'name' => $name,
            'started_at' => now(),
        ]);

        $this->tasks->broadcastUpdated($this->task->fresh());

        return $step;
    }

    /**
     * Conclude a step successfully, recording its structured output.
     *
     * @param  array<string, mixed>|null  $output
     */
    public function finishStep(TaskStep $step, ?array $output = null): TaskStep
    {
        $step->update([
            'task_status_id' => TaskStatus::idFor(TaskStatus::COMPLETED),
            'output' => $output,
            'finished_at' => now(),
        ]);

        $this->tasks->broadcastUpdated($this->task->fresh());

        return $step;
    }

    /**
     * Conclude a step with an error.
     */
    public function failStep(TaskStep $step, string $error): TaskStep
    {
        $step->update([
            'task_status_id' => TaskStatus::idFor(TaskStatus::FAILED),
            'error' => $error,
            'finished_at' => now(),
        ]);

        $this->tasks->broadcastUpdated($this->task->fresh());

        return $step;
    }

    /**
     * Append a leveled log line to the task (optionally to a step).
     */
    public function log(string $message, string $level = LogLevel::INFO, ?TaskStep $step = null): TaskLog
    {
        $allowed = [LogLevel::DEBUG, LogLevel::INFO, LogLevel::WARNING, LogLevel::ERROR];

        if (! in_array($level, $allowed, true)) {
            $level = LogLevel::INFO;
        }

        return $this->task->logs()->create([
            'task_step_id' => $step?->id,
            'log_level_id' => LogLevel::idFor($level),
            'message' => $message,
            'created_at' => now(),
        ]);
    }

    /**
     * Update the task progress. Monotonic: a lower value never overwrites a
     * higher one, and values are clamped to 0–100 (US-10.1).
     */
    public function setProgress(int $progress): void
    {
        $clamped = max(0, min(100, $progress));

        if ($clamped <= $this->task->progress) {
            return;
        }

        $this->task->update(['progress' => $clamped]);

        $this->tasks->broadcastProgress($this->task->fresh());
    }

    /**
     * The step the task is currently on (the last unfinished one).
     */
    public function currentStep(): ?TaskStep
    {
        return $this->task->steps()
            ->whereNull('finished_at')
            ->orderByDesc('position')
            ->first();
    }
}
