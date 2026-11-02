<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiModelResource;
use App\Models\AiModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lists the text models the operator may switch a conversation to (US-3.5).
 */
class ModelController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $models = AiModel::query()
            ->with('provider')
            ->active()
            ->text()
            ->whereHas('provider', fn (Builder $providers) => $providers->active())
            ->orderByDesc('is_default_text')
            ->orderBy('name')
            ->get();

        return AiModelResource::collection($models);
    }
}
