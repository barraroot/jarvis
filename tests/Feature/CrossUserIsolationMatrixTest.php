<?php

namespace Tests\Feature;

use App\Models\AgentExecution;
use App\Models\AiModel;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\ConnectorStatus;
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
use App\Models\Visualization;
use App\Models\VoiceSession;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappScheduleStatus;
use App\Models\WhatsappSession;
use App\Services\MemoryRetriever;
use App\Services\NotificationService;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use App\Tools\ToolResult;
use Database\Factories\MemoryEmbeddingFactory;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * US-1.3: every authenticated resource endpoint, listing, vector search and
 * broadcast channel is scoped to its owner. User B never reads, changes or
 * acts on user A's data, and never receives A's realtime events.
 */
class CrossUserIsolationMatrixTest extends TestCase
{
    use FakesWuzapi;
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
     * Voice and visualization endpoints (RNF-03): another user's resource is
     * 403, except CT-02's `voice_session_id`, resolved scoped to the caller
     * (404, no existence revealed). Placeholders in the payload resolve to
     * fixture ids.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: int}>
     */
    public static function voiceEndpoints(): array
    {
        return [
            'voice sessions renewal POST' => ['POST', 'voice/sessions', ['voice_session_id' => '{voiceSession}'], 403],
            'voice usage POST' => ['POST', 'voice/sessions/{voiceSession}/usage', [
                'response_id' => 'resp_intruder',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 403],
            'voice turns POST' => ['POST', 'voice/sessions/{voiceSession}/turns', [
                'turns' => [['role' => 'user', 'content' => 'Invadido', 'spoken_at' => '2026-11-04T12:00:00Z']],
            ], 403],
            'voice tasks foreign visualization POST' => ['POST', 'voice/tasks', [
                'voice_session_id' => '{intruderVoiceSession}',
                'request' => 'Invadido',
                'visualization_id' => '{visualization}',
            ], 403],
            'voice tasks foreign session POST' => ['POST', 'voice/tasks', [
                'voice_session_id' => '{voiceSession}',
                'request' => 'Invadido',
            ], 404],
            'visualizations close POST' => ['POST', 'visualizations/{visualization}/close', [], 403],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('voiceEndpoints')]
    public function test_intruder_is_denied_on_owner_voice_resources(string $method, string $uri, array $payload, int $expectedStatus): void
    {
        $this->actingAs($this->intruder);

        $payload = array_map(fn (mixed $value): mixed => is_string($value) && preg_match('/^\{(\w+)\}$/', $value, $match) === 1
            ? $this->records[$match[1]]->getKey()
            : $value, $payload);

        $this->json($method, '/api/v1/'.$this->resolve($uri), $payload)->assertStatus($expectedStatus);

        $this->assertOwnerDataIsIntact();
        $this->assertSame(0, Task::query()->where('user_id', $this->intruder->id)->count());

        Queue::assertNothingPushed();
        $this->assertNoBroadcastReached($this->intruder);
    }

    public function test_current_visualization_is_never_another_users(): void
    {
        $this->actingAs($this->intruder);

        $this->getJson('/api/v1/visualizations/current')->assertOk()->assertExactJson(['data' => null]);

        $this->actingAs($this->owner);

        $this->getJson('/api/v1/visualizations/current')
            ->assertOk()
            ->assertJsonPath('data.id', $this->records['visualization']->id);
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
        $this->postJson('/api/v1/'.$this->resolve('visualizations/{visualization}/close'))->assertOk();

        app(NotificationService::class)->notify($this->owner, NotificationType::TASK_COMPLETED, 'Concluída');

        $channels = $this->broadcastChannels();

        $this->assertNotEmpty($channels, 'The owner flows should broadcast realtime events.');
        $this->assertSame(['private-user.'.$this->owner->id], array_values(array_unique($channels)));
        $this->assertNoBroadcastReached($this->intruder);
    }

    /**
     * CT-08 (RNF-03): the owner's schedule is 403 to anyone else, for both
     * the active (cancel) and the paused (resume) transition.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function whatsappScheduleEndpoints(): array
    {
        return [
            'whatsapp schedule cancel POST' => ['cancel', WhatsappScheduleStatus::ACTIVE],
            'whatsapp schedule resume POST' => ['resume', WhatsappScheduleStatus::PAUSED],
        ];
    }

    #[DataProvider('whatsappScheduleEndpoints')]
    public function test_intruder_gets_403_on_owner_whatsapp_schedules(string $action, string $status): void
    {
        [$ownerSession] = $this->whatsappSessions();
        $schedule = $this->whatsappSchedule($ownerSession, $status);

        $this->actingAs($this->intruder);

        $this->postJson("/api/v1/whatsapp/schedules/{$schedule->id}/{$action}")->assertForbidden();

        $this->assertSame($status, $schedule->fresh()->load('status')->statusSlug());
        $this->assertSame(0, AuditLog::query()->where('subject_type', $schedule->getMorphClass())->count());

        $this->actingAs($this->owner);

        $this->getJson('/api/v1/whatsapp/schedules')->assertOk()->assertJsonPath('data.0.id', $schedule->id);
    }

    public function test_whatsapp_schedule_listing_never_includes_other_users_schedules(): void
    {
        [$ownerSession, $intruderSession] = $this->whatsappSessions();
        $ownerSchedule = $this->whatsappSchedule($ownerSession, WhatsappScheduleStatus::ACTIVE);
        $intruderSchedule = $this->whatsappSchedule($intruderSession, WhatsappScheduleStatus::ACTIVE);

        $this->actingAs($this->intruder);

        $ids = Arr::pluck($this->getJson('/api/v1/whatsapp/schedules')->assertOk()->json('data'), 'id');

        $this->assertSame([$intruderSchedule->id], $ids);
        $this->assertNotContains($ownerSchedule->id, $ids);
    }

    /**
     * CT-03..CT-07 have no id in the path: they always resolve the caller's
     * own session. The intruder only sees and changes their own session; the
     * owner's session, its token and its upstream user are never touched.
     */
    public function test_whatsapp_session_endpoints_only_reach_the_callers_own_session(): void
    {
        [$ownerSession, $intruderSession] = $this->whatsappSessions(intruderWuzapiUser: 'wuzapi-user-1');
        $ownerSnapshot = $ownerSession->only(['connector_status_id', 'wuzapi_user_id', 'suspended_at', 'last_error']);

        $this->actingAs($this->intruder);

        $this->getJson('/api/v1/whatsapp/session')->assertOk()->assertJsonPath('data.id', $intruderSession->id);
        $this->postJson('/api/v1/whatsapp/session')->assertOk()->assertJsonPath('data.id', $intruderSession->id);
        $this->postJson('/api/v1/whatsapp/session/qr')->assertOk();
        $this->postJson('/api/v1/whatsapp/session/pair-phone', ['phone' => '5511988887777'])->assertOk();
        $this->deleteJson('/api/v1/whatsapp/session')->assertOk();

        $this->assertNull($intruderSession->fresh());
        $this->assertNotNull($ownerSession->fresh());
        $this->assertSame($ownerSnapshot, $ownerSession->fresh()->only(array_keys($ownerSnapshot)));

        Http::assertNotSent(fn (Request $request): bool => $request->header('Token') === [$ownerSession->token]);
        $this->assertWuzapiCalled('DELETE', '/admin/users/wuzapi-user-1/full');
        $this->assertWuzapiCalled('DELETE', '/admin/users/'.$ownerSession->wuzapi_user_id.'/full', 0);

        $this->assertNoBroadcastReached($this->owner);

        // With no session of their own, the intruder gets nothing back — never the owner's.
        $this->getJson('/api/v1/whatsapp/session')->assertOk()->assertExactJson(['data' => null]);
        $this->postJson('/api/v1/whatsapp/session/qr')->assertNotFound();
        $this->deleteJson('/api/v1/whatsapp/session')->assertNotFound();

        $this->actingAs($this->owner);

        $this->getJson('/api/v1/whatsapp/session')->assertOk()->assertJsonPath('data.id', $ownerSession->id);
    }

    public function test_whatsapp_revoking_consent_only_affects_the_callers_data(): void
    {
        [$ownerSession, $intruderSession] = $this->whatsappSessions();
        $ownerMessage = WhatsappMessage::factory()->forSession($ownerSession)->create();
        $ownerSchedule = $this->whatsappSchedule($ownerSession, WhatsappScheduleStatus::ACTIVE);

        $this->actingAs($this->intruder);

        $this->deleteJson('/api/v1/whatsapp/consent')->assertOk();

        $this->assertNotNull($ownerMessage->fresh());
        $this->assertSame(WhatsappScheduleStatus::ACTIVE, $ownerSchedule->fresh()->load('status')->statusSlug());
        $this->assertTrue($this->owner->whatsappConsent()->first()->isValid());
        $this->assertFalse($this->intruder->whatsappConsent()->first()->isValid());
        $this->assertSame(ConnectorStatus::CONNECTED, $ownerSession->fresh()->load('status')->statusSlug());
    }

    /**
     * Tools (RNF-03): the intruder's read and cancel tools never reach the
     * owner's messages or schedules.
     */
    public function test_whatsapp_tools_never_reach_another_users_messages_or_schedules(): void
    {
        [$ownerSession] = $this->whatsappSessions();
        WhatsappMessage::factory()->forSession($ownerSession)->create(['chat_jid' => '5511988887777@s.whatsapp.net', 'text' => 'segredo do dono']);
        $ownerSchedule = $this->whatsappSchedule($ownerSession, WhatsappScheduleStatus::ACTIVE);

        $this->artisan('jarvis:tools:sync-internal')->assertSuccessful();

        $read = $this->runWhatsappTool($this->intruder, 'whatsapp.messages.read', ['chat_jid' => '5511988887777@s.whatsapp.net']);
        $this->assertTrue($read->isCompleted(), (string) $read->error);
        $this->assertSame([], $read->output['messages']);
        $this->assertStringNotContainsString('segredo do dono', $read->forModel());

        $chats = $this->runWhatsappTool($this->intruder, 'whatsapp.chats.list', []);
        $this->assertSame([], $chats->output['chats']);

        $listed = $this->runWhatsappTool($this->intruder, 'whatsapp.schedule.list', []);
        $this->assertNotContains($ownerSchedule->id, Arr::pluck($listed->output['schedules'], 'id'));

        $cancel = $this->runWhatsappTool($this->intruder, 'whatsapp.schedule.cancel', ['schedule_id' => $ownerSchedule->id]);
        $this->assertFalse($cancel->isCompleted());
        $this->assertSame(WhatsappScheduleStatus::ACTIVE, $ownerSchedule->fresh()->load('status')->statusSlug());

        $this->assertNoWuzapiSend();
    }

    /**
     * Owner and intruder, both consented with a session; the wuzapi fake
     * answers every call (RNF-08).
     *
     * @return array{0: WhatsappSession, 1: WhatsappSession}
     */
    private function whatsappSessions(string $intruderWuzapiUser = 'wuzapi-user-intruder'): array
    {
        $this->fakeWuzapi();

        // Model events (the session's webhook key hash, the consent hooks) still run; broadcasts stay faked.
        Event::except(fn (string $event): bool => str_starts_with($event, 'eloquent.'));

        return [
            WhatsappSession::factory()->connected()->withConsent()->create(['user_id' => $this->owner->id, 'wuzapi_user_id' => 'wuzapi-user-owner']),
            WhatsappSession::factory()->withConsent()->create(['user_id' => $this->intruder->id, 'wuzapi_user_id' => $intruderWuzapiUser]),
        ];
    }

    private function whatsappSchedule(WhatsappSession $session, string $status): WhatsappSchedule
    {
        $factory = WhatsappSchedule::factory()->forSession($session);

        if ($status === WhatsappScheduleStatus::PAUSED) {
            $factory = $factory->paused();
        }

        return $factory->create(['to_jid' => '5511988887777@s.whatsapp.net']);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function runWhatsappTool(User $user, string $identifier, array $input): ToolResult
    {
        $tool = Tool::query()->where('identifier', $identifier)->firstOrFail();
        $approval = $tool->requiresApproval()
            ? Approval::factory()->approved()->create(['user_id' => $user->id, 'tool_id' => $tool->id, 'input' => $input])
            : null;

        return app(ToolExecutor::class)->execute($tool, $input, new ToolContext($user, approval: $approval));
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

        $voiceSession = VoiceSession::factory()->for($this->owner)->create();
        $visualization = Visualization::factory()->for($this->owner)->create(['task_id' => $task->id]);

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
            'voiceSession' => $voiceSession,
            'intruderVoiceSession' => VoiceSession::factory()->for($this->intruder)->create(),
            'visualization' => $visualization,
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
        $this->assertNull($this->records['visualization']->fresh()->closed_at);
        $this->assertSame(0, $this->records['voiceSession']->fresh()->total_tokens);
        $this->assertSame(0, Message::query()->where('conversation_id', $this->records['voiceSession']->conversation_id)->count());
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
