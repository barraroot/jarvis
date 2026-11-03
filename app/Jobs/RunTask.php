<?php

namespace App\Jobs;

use App\Exceptions\TaskCancelledException;
use App\Jobs\Middleware\WithLogContext;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ExecutionStatus;
use App\Models\NotificationType;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\NotificationService;
use App\Services\RunOptions;
use App\Services\TaskContext;
use App\Services\TaskService;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one background task on the dedicated `tasks` queue (US-10.1, workflow 5):
 * the task goes `running` with `started_at`, the agent executes under a
 * {@see TaskContext} (steps, logs, progress) and the task ends `completed`
 * with its structured result — or `failed` with the recorded error. Approval
 * requests park the task in `waiting_approval` and end this job; the decision
 * re-dispatches it with `resumeFromStep`. The job runs strictly with the
 * task's own `user_id` in context — other users' data is never touched.
 */
class RunTask implements ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'tasks';

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly Task $task,
        public readonly int $resumeFromStep = 0,
    ) {
        $this->timeout = max(1, $this->task->timeout_seconds ?: app(Settings::class)->taskTimeoutSeconds());

        // Long tasks never share workers with chat/default jobs; dedicated
        // workers are recycled with `--max-jobs`/`--max-time` (see README).
        $this->onQueue(self::QUEUE);
    }

    /**
     * Log lines of this job carry the operator, task and conversation.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new WithLogContext([
            'user_id' => $this->task->user_id,
            'task_id' => $this->task->id,
            'conversation_id' => $this->task->conversation_id,
        ])];
    }

    public function handle(TaskService $tasks): void
    {
        $task = $this->task->fresh();

        if ($task === null || $task->isTerminal()) {
            return;
        }

        if ($task->hasStatus(TaskStatus::CANCELLED) || $tasks->cancelRequested($task)) {
            $tasks->markCancelled($task);

            return;
        }

        $agent = $task->agent;
        $user = $task->user;

        if ($agent === null || $user === null) {
            $tasks->markFailed($task, 'Agente ou usuário da tarefa indisponível.');

            return;
        }

        $tasks->markRunning($task);

        app()->instance(TaskContext::class, $context = app(TaskContext::class, [
            'task' => $task->fresh(),
            'resumeFromStep' => $this->resumeFromStep,
        ]));

        try {
            $execution = app(AgentRunner::class)->run(
                $agent,
                $user,
                $tasks->resumePrompt($task, $this->resumeFromStep),
                new RunOptions(conversation: $task->conversation?->fresh(), task: $task),
            );

            $task->refresh();

            if ($task->hasStatus(TaskStatus::CANCELLED) || $tasks->cancelRequested($task)) {
                $tasks->markCancelled($task);

                return;
            }

            // The agent asked for approval mid-run: the task stays parked and
            // the decision re-dispatches this job (US-8.3, US-10.3).
            if ($task->hasStatus(TaskStatus::WAITING_APPROVAL)) {
                return;
            }

            if ($this->executionFailed($execution)) {
                $tasks->markFailed($task, $execution->fresh()?->error ?? 'A execução do agente falhou.');
                $this->notify($user, $task, NotificationType::TASK_FAILED, 'Tarefa falhou', 'A tarefa falhou.');

                return;
            }

            $tasks->markCompleted($task, $execution);
            $this->notify($user, $task, NotificationType::TASK_COMPLETED, 'Tarefa concluída', 'A tarefa foi concluída.');
        } catch (TaskCancelledException) {
            $tasks->markCancelled($task->fresh());
        } catch (Throwable $exception) {
            $tasks->markFailed($task->fresh(), TaskService::errorFor($exception));
            $this->notify($user, $task, NotificationType::TASK_FAILED, 'Tarefa falhou', 'A tarefa falhou.');
        }
    }

    /**
     * The worker killed the job (timeout) after `handle` was interrupted:
     * the task must not stay `running` forever (US-10.1).
     */
    public function failed(Throwable $exception): void
    {
        $task = $this->task->fresh();

        if ($task === null || $task->isTerminal()) {
            return;
        }

        app(TaskService::class)->markFailed($task, TaskService::errorFor($exception));

        if ($task->user !== null) {
            app(NotificationService::class)->notify(
                $task->user,
                NotificationType::TASK_FAILED,
                'Tarefa falhou',
                "A tarefa \"{$task->title}\" falhou.",
                ['task_id' => $task->id],
            );
        }
    }

    /**
     * Operator notification on the task outcome (US-10.4).
     */
    private function notify(User $user, Task $task, string $typeSlug, string $title, string $body): void
    {
        app(NotificationService::class)->notify(
            $user,
            $typeSlug,
            $title,
            "{$body} Tarefa: \"{$task->title}\".",
            ['task_id' => $task->id],
        );
    }

    private function executionFailed(AgentExecution $execution): bool
    {
        return in_array(
            $execution->fresh()?->status?->slug,
            [ExecutionStatus::FAILED, ExecutionStatus::TIMED_OUT],
            true,
        );
    }
}
