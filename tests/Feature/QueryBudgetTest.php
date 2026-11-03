<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AiModel;
use App\Models\Approval;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\MemoryEmbedding;
use App\Models\Task;
use App\Models\TaskStep;
use App\Models\ToolExecution;
use App\Models\User;
use Database\Factories\MemoryEmbeddingFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Pgvector\Laravel\Vector;
use Tests\Concerns\CountsQueries;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * Performance guard (Notes & Conventions): listings eager load their
 * relations — the query count stays under budget and does not grow with the
 * number of rows — and the hot queries are served by the schema indexes.
 */
class QueryBudgetTest extends TestCase
{
    use CountsQueries;
    use RefreshDatabase;
    use SeedsLookups;

    /**
     * Maximum statements per listing request (auth, page, count, eager loads).
     */
    private const int BUDGET = 15;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_lazy_loading_is_prevented_outside_production(): void
    {
        $this->assertTrue(Model::preventsLazyLoading());
    }

    public function test_list_endpoints_stay_within_query_budget(): void
    {
        $this->seedRows(2);

        $small = [];

        foreach (['conversations', 'tasks', 'activity', 'memories'] as $endpoint) {
            $small[$endpoint] = $this->assertQueryCountLessThan(
                self::BUDGET,
                fn () => $this->getJson('/api/v1/'.$endpoint)->assertOk(),
                "GET /api/v1/{$endpoint} exceeded the query budget.",
            );
        }

        $this->seedRows(10);

        foreach ($small as $endpoint => $count) {
            $this->assertQueryCountLessThan(
                $count + 1,
                fn () => $this->getJson('/api/v1/'.$endpoint)->assertOk()->assertJsonPath('data', fn (array $rows): bool => count($rows) >= 12),
                "GET /api/v1/{$endpoint} runs more queries with more rows (N+1).",
            );
        }
    }

    public function test_memory_vector_search_uses_the_hnsw_index(): void
    {
        $model = AiModel::factory()->defaultEmbedding()->create();

        foreach (range(1, 5) as $index) {
            MemoryEmbedding::factory()
                ->for(Memory::factory()->for($this->user)->confirmed())
                ->for($model, 'model')
                ->vector([1.0, $index / 10])
                ->create();
        }

        $plan = $this->explain(
            'SELECT memory_id FROM memory_embeddings ORDER BY embedding <=> ? LIMIT 5',
            [new Vector(MemoryEmbeddingFactory::fakeVector([1.0, 0.0]))],
        );

        $this->assertStringContainsString('memory_embeddings_embedding_hnsw', $plan);
    }

    public function test_listing_queries_use_their_composite_indexes(): void
    {
        $this->seedRows(3);

        $this->assertStringContainsString(
            'conversations_user_id_last_activity_at_index',
            $this->explain('SELECT * FROM conversations WHERE user_id = ? ORDER BY last_activity_at DESC LIMIT 20', [$this->user->id]),
        );
        $this->assertStringContainsString(
            'tasks_user_id_task_status_id_index',
            $this->explain('SELECT * FROM tasks WHERE user_id = ? AND task_status_id = ?', [$this->user->id, 1]),
        );
        $this->assertStringContainsString(
            'memories_user_id_memory_status_id_index',
            $this->explain('SELECT * FROM memories WHERE user_id = ? AND memory_status_id = ?', [$this->user->id, 1]),
        );
        $this->assertStringContainsString(
            'agent_executions_user_id_started_at_index',
            $this->explain('SELECT * FROM agent_executions WHERE user_id = ? ORDER BY started_at DESC LIMIT 20', [$this->user->id]),
        );
        $this->assertStringContainsString(
            'messages_conversation_id_id_index',
            $this->explain('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 50', [1]),
        );
    }

    private function seedRows(int $count): void
    {
        $agent = Agent::factory()->create();

        foreach (range(1, $count) as $index) {
            Conversation::factory()->for($this->user)->create();

            $task = Task::factory()->for($this->user)->for($agent)->completed()->create();
            TaskStep::factory()->for($task)->completed()->count(2)->sequence(['position' => 1], ['position' => 2])->create();

            $execution = AgentExecution::factory()->for($this->user)->for($agent)->completed()->create();
            ToolExecution::factory()->create(['user_id' => $this->user->id, 'agent_execution_id' => $execution->id]);
            Approval::factory()->for($this->user)->create();

            Memory::factory()->for($this->user)->confirmed()->create([
                'source_conversation_id' => Conversation::query()->where('user_id', $this->user->id)->value('id'),
            ]);
        }
    }

    /**
     * The planner's choice with sequential scans disabled, so tiny test
     * tables still reveal which index serves the query.
     *
     * @param  list<mixed>  $bindings
     */
    private function explain(string $sql, array $bindings): string
    {
        return DB::transaction(function () use ($sql, $bindings): string {
            DB::statement('SET LOCAL enable_seqscan = off');

            return collect(DB::select('EXPLAIN '.$sql, $bindings))
                ->map(fn (object $row): string => (string) ($row->{'QUERY PLAN'} ?? ''))
                ->implode(PHP_EOL);
        });
    }
}
