<?php

namespace App\Http\Controllers\Api\V1\Voice;

use App\Exceptions\UsageLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Voice\StoreVoiceTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\User;
use App\Models\Visualization;
use App\Services\CostLimitGuard;
use App\Services\VoiceTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * CT-02: background task requested by voice. The session is looked up scoped
 * to the operator, so a foreign or missing one is a 404 that reveals nothing;
 * inactive session → 422, foreign visualization → 403, cost limit → 402. No
 * task is created on any error.
 */
class VoiceTaskController extends Controller
{
    public function __construct(
        private readonly VoiceTaskService $voiceTasks,
        private readonly CostLimitGuard $costLimit,
    ) {}

    public function store(StoreVoiceTaskRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $session = $user->voiceSessions()->findOrFail($request->integer('voice_session_id'));

        $this->voiceTasks->assertActive($session);

        $visualization = $request->filled('visualization_id')
            ? Visualization::query()->findOrFail($request->integer('visualization_id'))
            : null;

        if ($visualization !== null) {
            Gate::authorize('update', $visualization);
        }

        try {
            $this->costLimit->assertWithinLimit($user);
        } catch (UsageLimitExceededException) {
            abort(402, __('assistant.errors.usage_limit_exceeded'));
        }

        $task = $this->voiceTasks->start($user, $session, $request->validated('request'), $visualization);

        return TaskResource::make($task->fresh(['agent', 'status', 'steps', 'model']))
            ->response()
            ->setStatusCode(201);
    }
}
