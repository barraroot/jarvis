<?php

namespace Tests\Feature;

use App\Models\AgentExecution;
use App\Models\AiModel;
use App\Models\Approval;
use App\Models\Conversation;
use App\Models\McpServer;
use App\Models\McpToolDefinition;
use App\Models\Memory;
use App\Models\MemoryEmbedding;
use App\Models\Message;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\User;
use App\Services\MemoryRetriever;
use App\Services\NotificationService;
use Database\Factories\MemoryEmbeddingFactory;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * US-1.3: every authenticated resource endpoint, listing, vector search and
 * broadcast channel is scoped to its owner. User B never reads, changes or
 * acts on user A's data, and never receives A's realtime events.
 */
class CrossUserIsolationMatrixTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $owner;

    private User $intruder;

    /**
     * @var array<string, mixed>
     */
    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        Queue::fake();
        Event::fake();

        $this->owner = User::factory()->withPreference()->create();
        $this->intruder = User::factory()->withPreference()->create();

        $this->records = $this->ownerRecords();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2?: array<string, mixed>}>
     */
    public static function resourceEndpoints(): array
    {
        return [
            'conversations GET' => ['GET', 'conversations/{conversation}'],
            'conversations PATCH' => ['PATCH', 'conversations/{conversation}', ['title' => 'Invadido']],
            'conversations DELETE' => ['DELETE', 'conversations/{conversation}'],
            'messages POST' => ['POST', 'conversations/{conversation}/messages', ['content' => 'Oi', 'source' => 'text']],
            'memories PATCH' => ['PATCH', 'memories/{memory}', ['content' => 'Invadido']],
            'memories DELETE' => ['DELETE', 'memories/{memory}'],
            'memories confirm POST' => ['POST', 'memories/{hypothesis}/confirm'],
            'memories discard POST' => ['POST', 'memories/{hypothesis}/discard'],
            'tasks GET' => ['GET', 'tasks/{task}'],
            'tasks cancel POST' => ['POST', 'tasks/{task}/cancel'],
            'tasks retry POST' => ['POST', 'tasks/{failedTask}/retry'],
            'approvals GET' => ['GET', 'approvals/{approval}'],
            'approvals approve POST' => ['POST', 'approvals/{approval}/approve'],
            'approvals deny POST' => ['POST', 'approvals/{approval}/deny', ['reason' => 'não']],
            'notifications read POST' => ['POST', 'notifications/{notification}/read'],
            'connectors PATCH' => ['PATCH', 'connectors/{connector}', ['name' => 'Invadido']],
            'connectors DELETE' => ['DELETE', 'connectors/{connector}'],
            'connectors resync POST' => ['POST', 'connectors/{connector}/resync'],
            'connectors tools GET' => ['GET', 'connectors/{connector}/tools'],
            'connectors tools PATCH' => ['PATCH', 'connectors/{connector}/tools/{connectorTool}', ['is_active' => true]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('resourceEndpoints')]
    public function test_intruder_gets_403_or_404_on_owner_resources(string $method, string $uri, array $payload = []): void
    {
        $this->actingAs($this->intruder);

        $status = $this->json($method, '/api/v1/'.$this->resolve($uri), $payload)->status();

        $this->assertContains($status, [403, 404], "{$method} {$uri} answered {$status} to another user.");

        $this->assertOwnerDataIsIntact();

        Queue::assertNothingPushed();
        $this->assertNoBroadcastReached($this->intruder);
    }

    /**
     * The same endpoints stay reachable by their owner, so the 403/404 above
     * comes from ownership and not from a broken route.
     */
    public function test_owner_can_read_their_own_resources(): void
    {
        $this->actingAs($this->owner);

        $this->getJson('/api/v1/'.$this->resolve('conversations/{conversation}'))->assertOk();
        $this->getJson('/api/v1/'.$this->resolve('tasks/{task}'))->assertOk();
        $this->getJson('/api/v1/'.$this->resolve('approvals/{approval}'))->assertOk();
        $this->getJson('/api/v1/'.$this->resolve('connectors/{connector}/tools'))->assertOk();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function listingEndpoints(): array
    {
        return [
            'conversations' => ['conversations', 'conversation'],
            'memories' => ['memories', 'memory'],
            'tasks' => ['tasks', 'task'],
            'approvals' => ['approvals', 'approval'],
            'notifications' => ['notifications', 'notification'],
            'connectors' => ['connectors', 'connector'],
            'agent-executions' => ['agent-executions', 'agentExecution'],
        ];
    }

    #[DataProvider('listingEndpoints')]
    public function test_listings_never_include_other_users_records(string $uri, string $recordKey): void
    {
        $this->actingAs($this->owner);

        $ownerIds = Arr::pluck($this->getJson('/api/v1/'.$uri)->assertOk()->json('data'), 'id');
        $this->assertContains($this->records[$recordKey]->id, $ownerIds, "Owner should see their own {$uri}.");

        $this->actingAs($this->intruder);

        $intruderIds = Arr::pluck($this->getJson('/api/v1/'.$uri)->assertOk()->json('data'), 'id');
        $this->assertNotContains($this->records[$recordKey]->id, $intruderIds, "{$uri} leaked another user's record.");
    }

    public function test_activity_feed_never_includes_other_users_entries(): void
    {
        $this->actingAs($this->intruder);

        $entries = collect($this->getJson('/api/v1/activity')->assertOk()->json('data'))
            ->map(fn (array $entry): string => $entry['type'].':'.$entry['id']);

        $this->assertNotContains('agent:'.$this->records['agentExecution']->id, $entries);
        $this->assertNotContains('tool:'.$this->records['toolExecution']->id, $entries);
        $this->assertNotContains('approval:'.$this->records['approval']->id, $entries);

        $this->actingAs($this->owner);

        $ownerEntries = collect($this->getJson('/api/v1/activity')->assertOk()->json('data'))
            ->map(fn (array $entry): string => $entry['type'].':'.$entry['id']);

        $this->assertContains('agent:'.$this->records['agentExecution']->id, $ownerEntries);
    }

    public function test_preferences_are_per_user(): void
    {
        $this->actingAs($this->intruder);

        $this->putJson('/api/v1/preferences', ['locale' => 'en-US'])->assertOk();

        $this->assertSame('en-US', $this->intruder->preference()->first()->locale);
        $this->assertNotSame('en-US', $this->owner->preference()->first()->locale);

        $this->getJson('/api/v1/preferences')->assertOk()->assertJsonPath('data.locale', 'en-US');
    }

    public function test_vector_search_never_crosses_users(): void
    {
        $model = AiModel::factory()->defaultEmbedding()->create();

        MemoryEmbedding::factory()
            ->for($this->records['memory'])
            ->for($model, 'model')
            ->vector([1.0, 0.0, 0.0])
            ->create();

        Embeddings::fake(fn (): array => [MemoryEmbeddingFactory::fakeVector([1.0, 0.0, 0.0])]);

        $this->assertTrue(app(MemoryRetriever::class)->relevantFor($this->intruder, 'consulta')->isEmpty());
        $this->assertSame(
            [$this->records['memory']->id],
            app(MemoryRetriever::class)->relevantFor($this->owner, 'consulta')->pluck('id')->all(),
        );
    }

    public function test_owner_flows_only_broadcast_on_the_owner_channel(): void
    {
        $this->actingAs($this->owner);

        $this->postJson('/api/v1/'.$this->resolve('conversations/{conversation}/messages'), ['content' => 'Oi', 'source' => 'text'])
            ->assertAccepted();
        $this->postJson('/api/v1/'.$this->resolve('tasks/{task}/cancel'))->assertSuccessful();
        $this->postJson('/api/v1/'.$this->resolve('approvals/{approval}/deny'), ['reason' => 'não'])->assertOk();

        app(NotificationService::class)->notify($this->owner, NotificationType::TASK_COMPLETED, 'Concluída');

        $channels = $this->broadcastChannels();

        $this->assertNotEmpty($channels, 'The owner flows should broadcast realtime events.');
        $this->assertSame(['private-user.'.$this->owner->id], array_values(array_unique($channels)));
        $this->assertNoBroadcastReached($this->intruder);
    }

    /**
     * @return array<string, mixed>
     */
    private function ownerRecords(): array
    {
        $conversation = Conversation::factory()->for($this->owner)->create();
        Message::factory()->for($conversation)->fromUser()->create();

        $connector = McpServer::factory()->for($this->owner)->connected()->create();
        $definition = McpToolDefinition::factory()->for($connector, 'server')->create();
        $connectorTool = Tool::factory()->mcp()->create(['mcp_tool_definition_id' => $definition->id, 'is_active' => false]);

        $task = Task::factory()->for($this->owner)->running()->create();
        $agentExecution = AgentExecution::factory()->for($this->owner)->completed()->create();

        return [
            'conversation' => $conversation,
            'memory' => Memory::factory()->for($this->owner)->confirmed()->create(),
            'hypothesis' => Memory::factory()->for($this->owner)->hypothesis()->create(),
            'task' => $task,
            'failedTask' => Task::factory()->for($this->owner)->failed()->create(),
            'approval' => Approval::factory()->for($this->owner)->pending()->create(),
            'notification' => Notification::factory()->for($this->owner)->create(),
            'connector' => $connector,
            'connectorTool' => $connectorTool,
            'agentExecution' => $agentExecution,
            'toolExecution' => ToolExecution::factory()->create([
                'user_id' => $this->owner->id,
                'agent_execution_id' => $agentExecution->id,
            ]),
        ];
    }

    private function resolve(string $uri): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn (array $match): string => (string) $this->records[$match[1]]->getKey(), $uri);
    }

    private function assertOwnerDataIsIntact(): void
    {
        $this->assertNotNull($this->records['conversation']->fresh());
        $this->assertNotSame('Invadido', $this->records['conversation']->fresh()->title);
        $this->assertSame(1, $this->records['conversation']->messages()->count());
        $this->assertNotSame('Invadido', $this->records['memory']->fresh()?->content);
        $this->assertFalse($this->records['hypothesis']->fresh()->isConfirmed());
        $this->assertSame(TaskStatus::RUNNING, $this->records['task']->fresh()->statusSlug());
        $this->assertTrue($this->records['approval']->fresh()->isPending());
        $this->assertNull($this->records['notification']->fresh()->read_at);
        $this->assertNotNull($this->records['connector']->fresh());
        $this->assertNotSame('Invadido', $this->records['connector']->fresh()->name);
        $this->assertFalse($this->records['connectorTool']->fresh()->is_active);
        $this->assertSame(1, Task::query()->where('user_id', $this->owner->id)->where('id', '!=', $this->records['task']->id)->count());
    }

    private function assertNoBroadcastReached(User $user): void
    {
        $this->assertNotContains('private-user.'.$user->id, $this->broadcastChannels());
    }

    /**
     * Channel names of every broadcastable event dispatched so far.
     *
     * @return list<string>
     */
    private function broadcastChannels(): array
    {
        $channels = [];

        foreach (Event::dispatchedEvents() as $dispatches) {
            foreach ($dispatches as $arguments) {
                $event = $arguments[0] ?? null;

                if (! $event instanceof ShouldBroadcast) {
                    continue;
                }

                foreach (Arr::wrap($event->broadcastOn()) as $channel) {
                    $channels[] = $channel instanceof Channel ? $channel->name : (string) $channel;
                }
            }
        }

        return $channels;
    }
}
