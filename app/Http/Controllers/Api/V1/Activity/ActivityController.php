<?php

namespace App\Http\Controllers\Api\V1\Activity;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Activity\ListActivityRequest;
use App\Http\Resources\ActivityResource;
use App\Models\AgentExecution;
use App\Models\Approval;
use App\Models\ToolExecution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The operator's own activity timeline (US-12.4, US-1.3): agent executions,
 * tool executions and approvals merged by date into one paginated list (20).
 * The union only carries `(type, id, occurred_at)`; the page's rows are then
 * hydrated per type with their relations.
 */
class ActivityController extends Controller
{
    public const int PER_PAGE = 20;

    public function __invoke(ListActivityRequest $request): AnonymousResourceCollection
    {
        $userId = (int) $request->user()->getKey();
        $type = $request->validated('type');

        $sources = collect([
            'agent' => fn (): Builder => DB::table('agent_executions')
                ->where('user_id', $userId)
                ->selectRaw("'agent' AS type, id, COALESCE(started_at, created_at) AS occurred_at"),
            'tool' => fn (): Builder => DB::table('tool_executions')
                ->where('user_id', $userId)
                ->selectRaw("'tool' AS type, id, COALESCE(started_at, created_at) AS occurred_at"),
            'approval' => fn (): Builder => DB::table('approvals')
                ->where('user_id', $userId)
                ->selectRaw("'approval' AS type, id, COALESCE(decided_at, requested_at) AS occurred_at"),
        ])
            ->when($type !== null, fn (Collection $sources): Collection => $sources->only([$type]))
            ->map(fn (callable $source): Builder => $source());

        $union = $sources->reduce(fn (?Builder $carry, Builder $query): Builder => $carry?->unionAll($query) ?? $query);

        $page = DB::query()
            ->fromSub($union, 'activity')
            ->when($request->validated('from'), fn (Builder $query, string $from): Builder => $query
                ->where('occurred_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($request->validated('to'), fn (Builder $query, string $to): Builder => $query
                ->where('occurred_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByDesc('occurred_at')
            ->orderBy('type')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $models = $this->hydrate(collect($page->items()));

        $page->setCollection(collect($page->items())
            ->map(fn (object $row): ?Model => $models[$row->type][$row->id] ?? null)
            ->filter()
            ->values());

        return ActivityResource::collection($page);
    }

    /**
     * Load the rows of the current page, keyed by type then id.
     *
     * @param  Collection<int, object{type: string, id: int}>  $rows
     * @return array<string, Collection<int, Model>>
     */
    private function hydrate(Collection $rows): array
    {
        $ids = fn (string $type): array => $rows->where('type', $type)->pluck('id')->all();

        return [
            'agent' => AgentExecution::query()
                ->with(['agent', 'status', 'task:id,title'])
                ->whereKey($ids('agent'))
                ->get()
                ->keyBy('id'),
            'tool' => ToolExecution::query()
                ->with(['tool', 'status', 'agentExecution:id,conversation_id,task_id', 'taskStep:id,task_id'])
                ->whereKey($ids('tool'))
                ->get()
                ->keyBy('id'),
            'approval' => Approval::query()
                ->with(['tool', 'status', 'agentExecution:id,conversation_id', 'task:id,title,conversation_id'])
                ->whereKey($ids('approval'))
                ->get()
                ->keyBy('id'),
        ];
    }
}
