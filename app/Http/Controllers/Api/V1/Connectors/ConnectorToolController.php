<?php

namespace App\Http\Controllers\Api\V1\Connectors;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Connectors\ClassifyConnectorToolRequest;
use App\Http\Resources\ConnectorToolResource;
use App\Models\AuditAction;
use App\Models\McpServer;
use App\Models\Tool;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Tools discovered on a connector (US-9.3): listing for anyone who can see the
 * connector, explicit classification only for the owner of a personal one.
 */
class ConnectorToolController extends Controller
{
    public function index(Request $request, McpServer $connector): AnonymousResourceCollection
    {
        Gate::authorize('view', $connector);

        $definitions = $connector->toolDefinitions()
            ->with('tool')
            ->orderByDesc('is_available')
            ->orderBy('name')
            ->get();

        return ConnectorToolResource::collection($definitions);
    }

    public function update(ClassifyConnectorToolRequest $request, McpServer $connector, Tool $tool, AuditLogger $audit): ConnectorToolResource
    {
        $definition = $tool->mcpToolDefinition;

        abort_if($definition === null || $definition->mcp_server_id !== $connector->id, 404);

        if ($request->boolean('is_active') && ! $definition->is_available) {
            throw ValidationException::withMessages([
                'is_active' => 'A ferramenta não está mais disponível no servidor.',
            ]);
        }

        $tool->fill($request->validated());
        $changes = array_keys($tool->getDirty());
        $tool->save();

        if ($changes !== []) {
            $audit->log(AuditAction::TOOL_UPDATED, $request->user(), $tool, [
                'identifier' => $tool->identifier,
                'changes' => $tool->only($changes),
            ]);
        }

        return ConnectorToolResource::make($definition->setRelation('tool', $tool));
    }
}
