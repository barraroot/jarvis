<?php

namespace App\Http\Controllers\Api\V1\Connectors;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Connectors\StoreConnectorRequest;
use App\Http\Requests\Api\V1\Connectors\UpdateConnectorRequest;
use App\Http\Resources\ConnectorResource;
use App\Models\McpServer;
use App\Policies\McpServerPolicy;
use App\Services\ConnectorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Operator view of MCP connectors (US-9.1/9.2/9.4): active global connectors
 * (read-only) plus the operator's own personal ones, which they create, edit,
 * remove and resync. Authorization by {@see McpServerPolicy}.
 */
class ConnectorController extends Controller
{
    public function __construct(private readonly ConnectorService $connectors) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', McpServer::class);

        $user = $request->user();

        $connectors = McpServer::query()
            ->with('status')
            ->withCount([
                'toolDefinitions as tools_count' => fn (Builder $query) => $query->where('is_available', true),
                'toolDefinitions as active_tools_count' => fn (Builder $query) => $query
                    ->where('is_available', true)
                    ->whereHas('tool', fn (Builder $tool) => $tool->where('is_active', true)),
            ])
            ->where(function (Builder $query) use ($user): void {
                $query->where('user_id', $user->id)
                    ->orWhere(fn (Builder $global) => $global->whereNull('user_id')->where('is_active', true));
            })
            ->orderByRaw('user_id is null desc')
            ->orderBy('name')
            ->get();

        return ConnectorResource::collection($connectors);
    }

    public function store(StoreConnectorRequest $request): JsonResponse
    {
        $connector = $this->connectors->create($request->validated(), $request->user(), $request->user());

        return ConnectorResource::make($connector->load('status'))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateConnectorRequest $request, McpServer $connector): ConnectorResource
    {
        $connector = $this->connectors->update($connector, $request->validated(), $request->user());

        return ConnectorResource::make($connector->load('status'));
    }

    public function destroy(Request $request, McpServer $connector): JsonResponse
    {
        Gate::authorize('delete', $connector);

        $this->connectors->delete($connector, $request->user());

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function resync(Request $request, McpServer $connector): JsonResponse
    {
        Gate::authorize('resync', $connector);

        $this->connectors->resync($connector);

        return response()->json(['data' => ['queued' => true]], 202);
    }
}
