<?php

namespace App\Http\Controllers\Api\V1\Tasks;

use App\Exceptions\TaskNotCancellableException;
use App\Exceptions\TaskNotRetryableException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\ApprovalStatus;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Policies\TaskPolicy;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The operator's background tasks (US-10.2, US-10.3): active tasks for the
 * side panel (`?active=1`), the full lifecycle list (`?page`), the detail
 * with steps and paginated logs, and the cancel/retry actions. Owner gated by
 * {@see TaskPolicy}; state violations map to 409.
 */
class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tasks = Task::query()
            ->with(['agent', 'status', 'steps'])
            ->where('user_id', $request->user()->getKey())
            ->when(
                $request->query('active') === '1',
                fn ($query) => $query->whereIn('task_status_id', [
                    TaskStatus::idFor(TaskStatus::PENDING),
                    TaskStatus::idFor(TaskStatus::QUEUED),
                    TaskStatus::idFor(TaskStatus::RUNNING),
                    TaskStatus::idFor(TaskStatus::WAITING_APPROVAL),
                ]),
            )
            ->latest('id')
            ->paginate($request->query('active') === '1' ? 50 : 20);

        return TaskResource::collection($tasks);
    }

    public function show(Request $request, Task $task): TaskResource
    {
        Gate::authorize('view', $task);

        $task->load(['agent', 'status', 'steps']);

        // Pending approvals of a parked task render the ApprovalCard inline.
        if ($task->hasStatus(TaskStatus::WAITING_APPROVAL)) {
            $task->load(['approvals' => fn ($query) => $query
                ->where('approval_status_id', ApprovalStatus::idFor(ApprovalStatus::PENDING))
                ->with(['tool', 'status', 'agentExecution.agent']),
            ]);
        } else {
            $task->setRelation('approvals', collect());
        }

        $task->setRelation(
            'logs',
            $task->logs()
                ->with('level')
                ->latest('id')
                ->paginate(50, ['*'], 'logs_page', (int) $request->query('logs_page', 1)),
        );

        return TaskResource::make($task);
    }

    public function cancel(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('view', $task);

        try {
            $this->tasks->cancel($task, $request->user());
        } catch (TaskNotCancellableException $exception) {
            abort(409, $exception->getMessage());
        }

        return response()->json([
            'data' => TaskResource::make($task->fresh(['agent', 'status', 'steps']))->resolve(),
        ]);
    }

    public function retry(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('view', $task);

        try {
            $retry = $this->tasks->retry($task, $request->user());
        } catch (TaskNotRetryableException $exception) {
            abort(409, $exception->getMessage());
        }

        return response()->json([
            'data' => TaskResource::make($retry->fresh(['agent', 'status', 'steps']))->resolve(),
        ], 201);
    }
}
