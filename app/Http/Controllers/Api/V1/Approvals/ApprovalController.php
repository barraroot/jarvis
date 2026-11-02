<?php

namespace App\Http\Controllers\Api\V1\Approvals;

use App\Exceptions\ApprovalNotDecidableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Approvals\DenyApprovalRequest;
use App\Http\Resources\ApprovalResource;
use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The operator's tool approvals (US-8.3): list pending ones, inspect a single
 * request and approve/deny it. Owner gated by {@see ApprovalPolicy}; decisions
 * map to 409 when the window closed and 403 when someone else tries.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Approval::class);

        $approvals = Approval::query()
            ->with(['tool', 'status', 'agentExecution.agent'])
            ->where('user_id', $request->user()->getKey())
            ->when($request->query('status') === 'pending', fn ($query) => $query->where('approval_status_id', ApprovalStatus::idFor(ApprovalStatus::PENDING)))
            ->latest('requested_at')
            ->paginate(20);

        return ApprovalResource::collection($approvals);
    }

    public function show(Request $request, Approval $approval): ApprovalResource
    {
        Gate::authorize('view', $approval);

        return ApprovalResource::make($approval->load(['tool', 'status', 'agentExecution.agent']));
    }

    public function approve(Request $request, Approval $approval): JsonResponse
    {
        Gate::authorize('decide', $approval);

        $this->decide(fn (): Approval => $this->approvals->approve($approval, $request->user()));

        return response()->json([
            'data' => ApprovalResource::make($approval->fresh(['tool', 'status', 'agentExecution.agent']))->resolve(),
        ]);
    }

    public function deny(DenyApprovalRequest $request, Approval $approval): JsonResponse
    {
        Gate::authorize('decide', $approval);

        $this->decide(fn (): Approval => $this->approvals->deny($approval, $request->user(), $request->validated('reason')));

        return response()->json([
            'data' => ApprovalResource::make($approval->fresh(['tool', 'status', 'agentExecution.agent']))->resolve(),
        ]);
    }

    /**
     * @param  callable(): Approval  $decide
     *
     * @throws ApprovalNotDecidableException|JsonResponse
     */
    private function decide(callable $decide): Approval
    {
        try {
            return $decide();
        } catch (ApprovalNotDecidableException $exception) {
            abort($exception->reason === ApprovalNotDecidableException::NOT_OWNER ? 403 : 409, $exception->getMessage());
        }
    }
}
