<?php

namespace App\Services;

use App\Events\Task\TaskUpdated;
use App\Exceptions\TaskCancelledException;
use App\Exceptions\TaskNotCancellableException;
use App\Exceptions\TaskNotRetryableException;
use App\Jobs\RunTask;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AiModel;
use App\Models\ApprovalStatus;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\ResultType;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Visualization;
use App\Models\VoiceSession;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Task lifecycle (US-10.1, US-10.3): creation from a user message, queueing on
 * the dedicated `tasks` queue, cooperative cancellation via a Redis flag,
 * retry of failed tasks and the state mutations the `RunTask` job applies.
 * Every transition broadcasts `task.updated`.
 */
class TaskService
{
    /**
     * Redis key of the cooperative cancellation flag (US-10.3): the job checks
     * it before each step, so a cancelled task aborts at the next boundary.
     */
    public static function cancellationKey(int $taskId): string
    {
        return "task:{$taskId}:cancel_requested";
    }

    /**
     * Fixed pt-BR guidance appended to the prompt of voice tasks: the result is
     * shown in the HUD visualization panel through the internal tool.
     */
    public const string VOICE_PROMPT_SUFFIX = 'Esta tarefa foi pedida por voz no HUD. '
        .'Use a ferramenta visualization.update_section para exibir o andamento no painel de visualização: '
        .'comece pela seção "question" com a pergunta, publique os resultados encontrados na seção "results" '
        .'(marque o melhor com o badge "best") e use seções de texto para links ou respostas complementares. '
        .'Ao final, responda com um resumo curto do resultado, adequado para ser narrado por voz.';

    public function __construct(
        private readonly Settings $settings,
        private readonly VisualizationService $visualizations,
    ) {}

    /**
     * Turns a user message into a queued task owned by the message author,
     * executed by the given agent with the message content as request
     * (US-10.1): `pending` → `queued`, then `RunTask` is dispatched.
     */
    public function createFromMessage(Message $message, Agent $agent, string $title): Task
    {
        $task = Task::query()->create([
            'user_id' => $message->user_id,
            'agent_id' => $agent->id,
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'task_status_id' => TaskStatus::idFor(TaskStatus::PENDING),
            'title' => $title,
            'request' => $message->content,
            'progress' => 0,
            'timeout_seconds' => $this->settings->taskTimeoutSeconds(),
        ]);

        return $this->queue($task);
    }

    /**
     * Turns a voice request into a queued task (RF-11, CT-02): it lives in the
     * conversation of the given voice session — never "the most recent" — and
     * keeps the session, the open visualization being followed up (RF-19) and
     * the chosen model (D10). `pending` → `queued`, then `RunTask` is dispatched.
     */
    public function createFromVoice(User $user, VoiceSession $session, Agent $agent, string $request, ?Visualization $visualization, AiModel $model): Task
    {
        $task = Task::query()->create([
            'user_id' => $user->id,
            'agent_id' => $agent->id,
            'conversation_id' => $session->conversation_id,
            'message_id' => null,
            'voice_session_id' => $session->id,
            'visualization_id' => $visualization !== null && $visualization->closed_at === null ? $visualization->id : null,
            'ai_model_id' => $model->id,
            'task_status_id' => TaskStatus::idFor(TaskStatus::PENDING),
            'title' => Str::limit($request, 79, '…'),
            'request' => $request,
            'progress' => 0,
            'timeout_seconds' => $this->settings->taskTimeoutSeconds(),
        ]);

        return $this->queue($task);
    }

    /**
     * Park a task on the `tasks` queue: `pending` → `queued` with `queued_at`,
     * then the job is dispatched and the transition broadcast.
     */
    public function queue(Task $task): Task
    {
        $task->update([
            'task_status_id' => TaskStatus::idFor(TaskStatus::QUEUED),
            'queued_at' => $task->queued_at ?? now(),
        ]);

        RunTask::dispatch($task)->onQueue(RunTask::QUEUE)->afterCommit();

        $this->broadcastUpdated($task);

        return $task;
    }

    /**
     * Cooperative cancellation (US-10.3): only `queued|running|waiting_approval`
     * tasks may be cancelled. The Redis flag aborts a running job at its next
     * step boundary and pending approvals of the task are denied silently.
     *
     * @throws TaskNotCancellableException
     */
    public function cancel(Task $task, User $actor): Task
    {
        if (! $task->canBeCancelled()) {
            throw new TaskNotCancellableException($task);
        }

        Cache::put(self::cancellationKey($task->id), true, now()->addDay());

        $task->update([
            'task_status_id' => TaskStatus::idFor(TaskStatus::CANCELLED),
            'completed_at' => $task->completed_at ?? now(),
        ]);

        $task->approvals()
            ->where('approval_status_id', ApprovalStatus::idFor(ApprovalStatus::PENDING))
            ->update([
                'approval_status_id' => ApprovalStatus::idFor(ApprovalStatus::DENIED),
                'decided_at' => now(),
                'decided_by_user_id' => $actor->id,
                'decision_reason' => 'tarefa cancelada',
            ]);

        $this->broadcastUpdated($task);
        $this->finishVisualization($task);

        return $task;
    }

    /**
     * Retry a failed task (US-10.3): a fresh task linked via `retry_of_task_id`
     * with the same request, agent and conversation, queued like any other.
     *
     * @throws TaskNotRetryableException
     */
    public function retry(Task $task, User $actor): Task
    {
        if (! $task->canBeRetried()) {
            throw new TaskNotRetryableException($task);
        }

        $retry = Task::query()->create([
            'user_id' => $task->user_id,
            'agent_id' => $task->agent_id,
            'conversation_id' => $task->conversation_id,
            'message_id' => $task->message_id,
            'task_status_id' => TaskStatus::idFor(TaskStatus::PENDING),
            'retry_of_task_id' => $task->id,
            'title' => $task->title,
            'request' => $task->request,
            'progress' => 0,
            'timeout_seconds' => $task->timeout_seconds,
        ]);

        return $this->queue($retry);
    }

    /**
     * First run moves `queued` → `running` and stamps `started_at`; resumed
     * runs (already `running`) are left untouched.
     */
    public function markRunning(Task $task): Task
    {
        if ($task->hasStatus(TaskStatus::PENDING, TaskStatus::QUEUED)) {
            $task->update([
                'task_status_id' => TaskStatus::idFor(TaskStatus::RUNNING),
                'started_at' => $task->started_at ?? now(),
            ]);

            $this->broadcastUpdated($task);
        }

        return $task;
    }

    /**
     * Successful end of a run: structured result (text payload for now),
     * 100% progress and `completed_at`.
     */
    public function markCompleted(Task $task, AgentExecution $execution): Task
    {
        $fresh = $execution->fresh();

        $output = $fresh?->meta['output'] ?? '';
        $result = $fresh?->meta['result'] ?? ['type' => ResultType::TEXT, 'payload' => ['type' => ResultType::TEXT, 'text' => (string) $output]];

        $task->update([
            'task_status_id' => TaskStatus::idFor(TaskStatus::COMPLETED),
            'progress' => 100,
            'result_type_id' => ResultType::idFor($result['type']),
            'result' => $result['payload'],
            'completed_at' => now(),
        ]);

        $this->broadcastUpdated($task);
        $this->finishVisualization($task);

        return $task;
    }

    /**
     * A run that failed (or timed out) ends the task with the recorded error.
     */
    public function markFailed(Task $task, string $error): Task
    {
        $task->update([
            'task_status_id' => TaskStatus::idFor(TaskStatus::FAILED),
            'error' => $error,
            'completed_at' => $task->completed_at ?? now(),
        ]);

        $this->broadcastUpdated($task);
        $this->finishVisualization($task);

        return $task;
    }

    /**
     * Cancelled while the job was still working (flag or exception).
     */
    public function markCancelled(Task $task): Task
    {
        if (! $task->hasStatus(TaskStatus::CANCELLED)) {
            $task->update([
                'task_status_id' => TaskStatus::idFor(TaskStatus::CANCELLED),
                'completed_at' => $task->completed_at ?? now(),
            ]);

            $this->broadcastUpdated($task);
            $this->finishVisualization($task);
        }

        Cache::forget(self::cancellationKey($task->id));

        return $task;
    }

    /**
     * Whether the owner asked to cancel the task (cooperative flag, Redis).
     */
    public function cancelRequested(Task $task): bool
    {
        return Cache::get(self::cancellationKey($task->id)) === true;
    }

    /**
     * The position of the last finished step: where a resumed run continues
     * from (finished steps are not repeated, US-10.3).
     */
    public function resumeFromStep(Task $task): int
    {
        return (int) ($task->steps()->whereNotNull('finished_at')->max('position') ?? 0);
    }

    /**
     * The prompt of a resumed run: the original request prefixed with the
     * steps already concluded, so the agent continues instead of repeating.
     * Voice tasks also get the visualization guidance suffix.
     */
    public function resumePrompt(Task $task, int $resumeFromStep): string
    {
        $suffix = $task->voice_session_id !== null ? "\n\n".self::VOICE_PROMPT_SUFFIX : '';

        if ($resumeFromStep <= 0) {
            return $task->request.$suffix;
        }

        $finished = $task->steps()
            ->whereNotNull('finished_at')
            ->orderBy('position')
            ->pluck('name')
            ->all();

        $prefix = 'Retomando a tarefa após uma pausa. '
            .'Etapas já concluídas: '.implode('; ', $finished).'. '
            .'Continue da próxima etapa, sem repetir etapas concluídas.';

        return $prefix."\n\nSolicitação original:\n".$task->request.$suffix;
    }

    /**
     * Broadcast the task snapshot on every transition.
     */
    public function broadcastUpdated(Task $task): void
    {
        TaskUpdated::dispatch($task);
    }

    /**
     * The visualization the task last updated follows its end (RF-18):
     * `completed` → `updated`, `failed`/`cancelled` → `failed`.
     */
    private function finishVisualization(Task $task): void
    {
        if ($task->visualization_id !== null) {
            $this->visualizations->finishForTask($task);
        }
    }

    /**
     * Progress updates are broadcast too, but throttled to one event per
     * 500 ms per task (US-10.2): high-frequency updates would flood the
     * private channel without adding information.
     */
    public function broadcastProgress(Task $task): void
    {
        $key = "task:{$task->id}:progress_event_at";

        $last = Cache::get($key);

        if (is_numeric($last) && (microtime(true) - (float) $last) < 0.5) {
            return;
        }

        Cache::put($key, microtime(true), 60);

        TaskUpdated::dispatch($task);
    }

    /**
     * User-facing error for a failed run: job timeouts surface as `timeout`.
     */
    public static function errorFor(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'timeout';
        }

        return $exception->getMessage();
    }

    /**
     * Abort helper shared by the job and the task tools: a cancelled task
     * never starts another step (US-10.3).
     *
     * @throws TaskCancelledException
     */
    public function assertNotCancelled(Task $task): void
    {
        if ($task->fresh()?->hasStatus(TaskStatus::CANCELLED) || $this->cancelRequested($task)) {
            throw new TaskCancelledException($task);
        }
    }

    /**
     * Whether an agent execution status counts as a task failure.
     */
    public static function isFailedExecution(AgentExecution $execution): bool
    {
        return in_array($execution->status->slug, [ExecutionStatus::FAILED, ExecutionStatus::TIMED_OUT], true);
    }
}
