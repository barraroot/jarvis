<?php

namespace App\Http\Controllers\Api\V1\Memories;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Memories\StoreMemoryRequest;
use App\Http\Requests\Api\V1\Memories\UpdateMemoryRequest;
use App\Http\Resources\MemoryResource;
use App\Models\Memory;
use App\Models\MemoryStatus;
use App\Policies\MemoryPolicy;
use App\Services\MemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The operator's long-term memories (US-6.4): paginated list with filters and
 * search, explicit creation (always confirmed), edit, hard delete, and the
 * confirm/discard decision over hypotheses (US-6.2). Every route is owner
 * gated by {@see MemoryPolicy}.
 */
class MemoryController extends Controller
{
    public function __construct(private readonly MemoryService $memoryService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Memory::class);

        $user = $request->user();

        $memories = Memory::query()
            ->with(['status', 'category', 'sourceConversation'])
            ->where('user_id', $user->getKey())
            ->when($request->filled('status'), fn ($query) => $query->whereHas('status', fn ($status) => $status->where('slug', $request->string('status')->toString())))
            ->when($request->filled('category'), fn ($query) => $query->whereHas('category', fn ($category) => $category->where('slug', $request->string('category')->toString())))
            ->when($request->filled('q'), fn ($query) => $query->where('content', 'ilike', '%'.$request->string('q')->toString().'%'))
            ->latest('id')
            ->paginate(20);

        return MemoryResource::collection($memories)->additional([
            'summary' => [
                'confirmed' => Memory::query()->where('user_id', $user->getKey())->confirmed()->count(),
                'hypotheses' => Memory::query()->where('user_id', $user->getKey())->hypotheses()->count(),
            ],
        ]);
    }

    public function store(StoreMemoryRequest $request): JsonResponse
    {
        $memory = $this->memoryService->remember(
            $request->user(),
            $request->validated('content'),
            $request->validated('category'),
            MemoryStatus::CONFIRMED,
            explicit: true,
        );

        return (new MemoryResource($memory))
            ->response()
            ->setStatusCode($memory->wasRecentlyCreated ? 201 : 200);
    }

    public function update(UpdateMemoryRequest $request, Memory $memory): MemoryResource
    {
        $memory = $this->memoryService->update($memory, $request->validated('content'));

        return MemoryResource::make($memory);
    }

    public function destroy(Request $request, Memory $memory): JsonResponse
    {
        Gate::authorize('delete', $memory);

        $this->memoryService->delete($memory, $request->user());

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function confirm(Request $request, Memory $memory): MemoryResource
    {
        Gate::authorize('confirm', $memory);

        return MemoryResource::make($this->memoryService->confirm($memory));
    }

    public function discard(Request $request, Memory $memory): JsonResponse
    {
        Gate::authorize('delete', $memory);

        $this->memoryService->discard($memory, $request->user());

        return response()->json(['data' => ['deleted' => true]]);
    }
}
