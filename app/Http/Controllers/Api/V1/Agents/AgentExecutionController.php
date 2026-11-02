<?php

namespace App\Http\Controllers\Api\V1\Agents;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentExecutionResource;
use App\Models\AgentExecution;
use App\Models\ExecutionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The operator's agent execution history (US-7.4, US-12.4): `?active=1`
 * returns the running top-level executions, otherwise the paginated finished
 * history — both with the delegation chain nested under each parent.
 * Owner-gated by {@see AgentExecutionPolicy}.
 */
class AgentExecutionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AgentExecution::class);

        $query = AgentExecution::query()
            ->where('user_id', $request->user()->getKey())
            ->whereNull('parent_agent_execution_id')
            ->with($this->chainRelations())
            ->latest('started_at');

        if ($request->boolean('active')) {
            return AgentExecutionResource::collection(
                $query->where('execution_status_id', ExecutionStatus::idFor(ExecutionStatus::RUNNING))->get(),
            );
        }

        return AgentExecutionResource::collection(
            $query
                ->whereIn('execution_status_id', [
                    ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
                    ExecutionStatus::idFor(ExecutionStatus::FAILED),
                    ExecutionStatus::idFor(ExecutionStatus::TIMED_OUT),
                ])
                ->paginate(20),
        );
    }

    /**
     * The delegation chain, one level deeper than the maximum depth (3) so
     * every node can nest its children.
     *
     * @return list<string>
     */
    private function chainRelations(): array
    {
        return [
            'agent', 'status',
            'children.agent', 'children.status',
            'children.children.agent', 'children.children.status',
            'children.children.children.agent', 'children.children.children.status',
            'children.children.children.children.agent', 'children.children.children.children.status',
        ];
    }
}
