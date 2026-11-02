<?php

namespace App\Http\Controllers\Api\V1\Agents;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentSummaryResource;
use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lists the active agents the operator can force with an `@slug` prefix or
 * pick from the selector (US-7.4). Global configuration — like the model
 * list, it needs no ownership policy.
 */
class AgentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $agents = Agent::query()
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return AgentSummaryResource::collection($agents);
    }
}
