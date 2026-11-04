<?php

namespace App\Http\Controllers\Api\V1\Visualizations;

use App\Http\Controllers\Controller;
use App\Http\Resources\VisualizationResource;
use App\Models\Visualization;
use App\Policies\VisualizationPolicy;
use App\Services\VisualizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The HUD visualization panel (CT-08, CT-09): restore the latest open
 * visualization on load (RF-19b) and close it with `×` without touching the
 * linked task (RF-19a). Owner gated by {@see VisualizationPolicy}.
 */
class VisualizationController extends Controller
{
    public function __construct(private readonly VisualizationService $visualizations) {}

    public function current(Request $request): JsonResponse|VisualizationResource
    {
        $visualization = Visualization::query()
            ->with('status')
            ->where('user_id', $request->user()->getKey())
            ->open()
            ->latest('updated_at')
            ->latest('id')
            ->first();

        if ($visualization === null) {
            return response()->json(['data' => null]);
        }

        return VisualizationResource::make($visualization);
    }

    public function close(Visualization $visualization): VisualizationResource
    {
        Gate::authorize('update', $visualization);

        return VisualizationResource::make($this->visualizations->close($visualization));
    }
}
