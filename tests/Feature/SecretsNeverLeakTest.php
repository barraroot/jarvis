<?php

namespace Tests\Feature;

use App\Ai\Agents\IntentClassifier;
use App\Events\Tool\ToolCompleted;
use App\Events\Tool\ToolStarted;
use App\Events\Visualization\VisualizationUpdated;
use App\Events\Whatsapp\SessionStatusChanged;
use App\Jobs\DeliverWhatsappOutbound;
use App\Jobs\ProcessWuzapiEvent;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\ConnectorStatus;
use App\Models\Conversation;
use App\Models\McpServer;
use App\Models\McpToolDefinition;
use App\Models\Memory;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Task;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\User;
use App\Models\Visualization;
use App\Models\VoiceSession;
use App\Models\WhatsappConsent;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundStatus;
use App\Models\WhatsappSession;
use App\Services\WhatsappOutboundService;
use App\Services\WhatsappSessionService;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use App\Tools\ToolResult;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * Project-description §4.1/§5: provider API keys, connector/tool credentials
 * and passwords never leave the backend — neither through any authenticated
 * `GET /api/v1/*` endpoint nor through any broadcast payload.
 */
class SecretsNeverLeakTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    /**
     * JSON keys and values that must never appear in a response body.
     */
    private const array FORBIDDEN_PATTERNS = [
        '/sk-/i',
        '/"api_key"/i',
        '/"credential"/i',
        '/"password"/i',
        '/"remember_token"/i',
    ];

    private User $user;

    /**
     * @var array<string, mixed>
     */
    private array $records = [];

    /**
     * @var list<string>
     */
    private array $secrets = [];

    /**
     * @var list<ProcessWuzapiEvent>
     */
    private array $processedWebhooks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        config(['broadcasting.default' => 'null']);

        $this->user = User::factory()->withPreference()->create(['password' => 'senha-super-secreta']);
        $this->records = $this->seedRecordsWithSecrets();
    }

    protected function tearDown(): void
    {
        if (Queue::getFacadeRoot() instanceof QueueFake) {
            Queue::releaseUniqueJobLocks();
        }

        parent::tearDown();
    }

    public function test_authenticated_get_endpoints_never_expose_secrets(): void
    {
        $this->actingAs($this->user);

        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => in_array('GET', $route->methods(), true)
                && str_starts_with($route->uri(), 'api/v1/')
                && in_array('auth:sanctum', $route->gatherMiddleware(), true));

        $this->assertGreaterThanOrEqual(15, $routes->count(), 'The sweep should cover every authenticated GET endpoint.');

        foreach ($routes as $route) {
            $uri = '/'.$this->resolveParameters($route->uri());

            $response = $this->getJson($uri);

            $this->assertTrue($response->isSuccessful(), "GET {$uri} answered {$response->status()}.");

            $this->assertBodyHasNoSecrets((string) $response->getContent(), "GET {$uri}");
        }
    }

    public function test_broadcast_payloads_never_expose_secrets(): void
    {
        $events = $this->broadcastableEvents();

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $payload = (string) json_encode($this->broadcastPayload($event), JSON_UNESCAPED_UNICODE);

            $this->assertBodyHasNoSecrets($payload, 'broadcast '.$event::class);
        }
    }

    /**
     * CT-01, CT-02, CT-07, CT-08, CT-09 and CT-10 (RNF-01): the voice and
     * visualization endpoints never echo the provider key, even when the
     * backend talks to the provider with it.
     */
    public function test_voice_and_visualization_endpoints_never_expose_secrets(): void
    {
        Queue::fake();
        Http::fake([
            '*/realtime/client_secrets' => Http::response(['value' => 'ek_ephemeral_secret', 'expires_at' => now()->addMinute()->timestamp]),
        ]);
        IntentClassifier::fake(fn (): array => ['intent' => 'use_tool', 'agent_slug' => null, 'confidence' => 0.9]);
        Agent::factory()->default()->create();

        $this->actingAs($this->user);

        $session = $this->records['voice_session'];
        $visualization = $this->records['visualization'];

        $responses = [
            'CT-01 create' => $this->postJson('/api/v1/voice/sessions', ['locale' => 'pt-BR'])->assertCreated(),
            'CT-01 renew' => $this->postJson('/api/v1/voice/sessions', ['voice_session_id' => $session->id])->assertCreated(),
            'CT-02' => $this->postJson('/api/v1/voice/tasks', [
                'voice_session_id' => $session->id,
                'request' => 'Busque a aula de inglês',
                'visualization_id' => $visualization->id,
            ])->assertCreated(),
            'CT-07' => $this->postJson("/api/v1/voice/sessions/{$session->id}/usage", [
                'response_id' => 'resp_secret_sweep',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])->assertCreated(),
            'CT-08' => $this->getJson('/api/v1/visualizations/current')->assertOk()->assertJsonPath('data.id', $visualization->id),
            'CT-09' => $this->postJson("/api/v1/visualizations/{$visualization->id}/close")->assertOk(),
            'CT-10' => $this->postJson("/api/v1/voice/sessions/{$session->id}/turns", [
                'turns' => [['role' => 'assistant', 'content' => 'Pronto.', 'spoken_at' => now()->toIso8601String()]],
            ])->assertCreated(),
        ];

        foreach ($responses as $label => $response) {
            $this->assertBodyHasNoSecrets((string) $response->getContent(), $label);
        }

        // The provider was called with the key server-side only.
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer '.$this->secrets[0]));
    }

    public function test_sweep_covers_visualization_and_enriched_tool_events(): void
    {
        $classes = array_map(fn (ShouldBroadcast $event): string => $event::class, $this->broadcastableEvents());

        foreach ([VisualizationUpdated::class, ToolStarted::class, ToolCompleted::class, SessionStatusChanged::class] as $class) {
            $this->assertContains($class, $classes);
        }
    }

    /**
     * RNF-01 across the real WhatsApp flows: the session token, HMAC key,
     * webhook key (and its hash), the admin token, the QR and the pairing
     * code never reach `GET /api/v1/*`, CT-10 payloads, `audit_logs`,
     * `tool_executions` or the logs, even when wuzapi echoes them back in
     * its errors. The QR and code only appear in the CT-05/CT-06 answers.
     */
    public function test_whatsapp_flows_never_leak_session_secrets(): void
    {
        Queue::fake();
        Sleep::fake();
        Event::fake([SessionStatusChanged::class]);

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged): void {
            $logged[] = $log->message.' '.json_encode($log->context, JSON_UNESCAPED_UNICODE);
        });

        $qr = 'data:image/png;base64,'.Str::random(48);
        $code = Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4));
        // wuzapi knows the session token, HMAC key, webhook key and admin token, and echoes them back.
        $upstreamSecrets = [];
        $echo = function () use (&$upstreamSecrets): mixed {
            return Http::response(['code' => 500, 'error' => 'boom '.implode(' ', $upstreamSecrets), 'success' => false], 500);
        };

        $this->fakeWuzapi([
            'GET /session/qr' => ['QRCode' => $qr],
            'POST /session/pairphone' => ['LinkingCode' => $code],
            'POST /chat/send/text' => $echo,
            'GET /group/list' => $echo,
            'GET /session/status' => $echo,
            'POST /session/logout' => $echo,
        ]);
        $this->artisan('jarvis:tools:sync-internal')->assertSuccessful();

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $this->postJson('/api/v1/whatsapp/consent', ['version' => WhatsappConsent::CURRENT_VERSION, 'scopes' => WhatsappConsent::SCOPES])->assertOk();
        $provisioned = $this->postJson('/api/v1/whatsapp/session')->assertCreated();

        $session = WhatsappSession::query()->where('user_id', $owner->id)->sole();
        $upstreamSecrets = [(string) $session->token, (string) $session->hmac_key, (string) $session->webhook_key, $this->wuzapiAdminToken];
        $this->secrets = [...$this->secrets, ...$upstreamSecrets, (string) $session->webhook_key_hash, $qr, $code];

        (new UniqueLock(Cache::store()))->release(new DeliverWhatsappOutbound($session->id));
        Cache::lock(WhatsappOutboundService::deliveryLockKey($session->id))->forceRelease();
        Cache::lock(WhatsappOutboundService::guardLockKey($session->id))->forceRelease();

        // CT-05/CT-06: the QR and the code reach only their own answer.
        $this->postJson('/api/v1/whatsapp/session/qr')->assertOk()->assertJsonPath('data.qr', $qr);
        $this->postJson('/api/v1/whatsapp/session/pair-phone', ['phone' => '5511988887777'])->assertOk()->assertJsonPath('data.code', $code);

        $this->deliverWebhook($session, $this->wuzapiConnectionEvent('Connected'));
        $this->deliverWebhook($session, $this->wuzapiMessageEvent(['event' => ['Message' => ['conversation' => 'Oi!']]]));

        $send = $this->executeWhatsappTool($owner, 'whatsapp.send.text', ['to' => '5511988887777@s.whatsapp.net', 'text' => 'Olá']);
        $this->assertTrue($send->isCompleted(), (string) $send->error);
        foreach (Queue::pushed(DeliverWhatsappOutbound::class) as $job) {
            (new UniqueLock(Cache::store()))->release($job);
            $job->handle(app(WhatsappOutboundService::class));
        }
        $this->assertSame(WhatsappOutboundStatus::FAILED, WhatsappOutboundMessage::query()->with('status')->sole()->status->slug);

        $this->assertFalse($this->executeWhatsappTool($owner, 'whatsapp.groups.list', [])->isCompleted());
        $this->assertTrue($this->executeWhatsappTool($owner, 'whatsapp.schedule.create', [
            'to' => '5511988887777@s.whatsapp.net',
            'kind' => 'text',
            'text' => 'Lembrete',
            'run_at' => now()->addDay()->toIso8601String(),
        ])->isCompleted());
        $this->assertTrue($this->executeWhatsappTool($owner, 'whatsapp.session.status', [])->isCompleted());

        $this->artisan('jarvis:whatsapp:health-check', ['--force' => true])->assertSuccessful();
        $this->assertSame(ConnectorStatus::ERROR, $session->fresh()->load('status')->statusSlug());

        $admin = User::factory()->admin()->create();
        app(WhatsappSessionService::class)->suspend($session->fresh(), $admin);
        app(WhatsappSessionService::class)->resume($session->fresh(), $admin);

        $bodies = [
            'CT-04' => (string) $provisioned->getContent(),
            'CT-03' => (string) $this->getJson('/api/v1/whatsapp/session')->assertOk()->assertJsonPath('data.status', ConnectorStatus::ERROR)->getContent(),
            'CT-08' => (string) $this->getJson('/api/v1/whatsapp/schedules')->assertOk()->assertJsonCount(1, 'data')->getContent(),
        ];

        $this->deleteJson('/api/v1/whatsapp/consent')->assertOk();
        $this->deleteJson('/api/v1/whatsapp/session')->assertOk();

        $bodies['CT-10'] = (string) json_encode(
            Event::dispatched(SessionStatusChanged::class)->map(fn (array $arguments): array => $this->broadcastPayload($arguments[0]))->all(),
            JSON_UNESCAPED_UNICODE,
        );
        $bodies['audit_logs.properties'] = (string) json_encode(AuditLog::query()->pluck('properties')->all(), JSON_UNESCAPED_UNICODE);
        $bodies['tool_executions'] = (string) json_encode(
            ToolExecution::query()->get(['output', 'error'])->map(fn (ToolExecution $execution): array => [$execution->output, $execution->error])->all(),
            JSON_UNESCAPED_UNICODE,
        );
        $bodies['logs'] = implode(PHP_EOL, $logged);

        $this->assertGreaterThanOrEqual(5, Event::dispatched(SessionStatusChanged::class)->count());
        $this->assertGreaterThanOrEqual(8, AuditLog::query()->count());
        $this->assertGreaterThanOrEqual(5, ToolExecution::query()->count());
        $this->assertNotSame('', $bodies['logs'], 'The failed logout should be logged.');
        $this->assertStringContainsString('boom', $bodies['tool_executions'], 'wuzapi errors should reach the execution, redacted.');

        foreach ($bodies as $label => $body) {
            $this->assertBodyHasNoSecrets($body, $label);
        }
    }

    /**
     * Post a signed webhook event and run the queued job as the worker would.
     *
     * @param  array<string, mixed>  $payload
     */
    private function deliverWebhook(WhatsappSession $session, array $payload): void
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            route('api.webhooks.wuzapi', ['webhook_key' => $session->webhook_key]),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HMAC_SIGNATURE' => hash_hmac('sha256', $body, (string) $session->hmac_key),
            ],
            content: $body,
        )->assertAccepted();

        Queue::pushed(ProcessWuzapiEvent::class)
            ->reject(fn (ProcessWuzapiEvent $job): bool => in_array($job, $this->processedWebhooks, true))
            ->each(function (ProcessWuzapiEvent $job): void {
                $this->processedWebhooks[] = $job;
                app()->call([$job, 'handle']);
            });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function executeWhatsappTool(User $user, string $identifier, array $input): ToolResult
    {
        $tool = Tool::query()->where('identifier', $identifier)->firstOrFail();
        $approval = $tool->requiresApproval()
            ? Approval::factory()->approved()->create(['user_id' => $user->id, 'tool_id' => $tool->id, 'input' => $input])
            : null;

        return app(ToolExecutor::class)->execute($tool, $input, new ToolContext($user, approval: $approval));
    }

    private function assertBodyHasNoSecrets(string $body, string $label): void
    {
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $body, "{$label} matched {$pattern}.");
        }

        foreach ($this->secrets as $secret) {
            $this->assertStringNotContainsString($secret, $body, "{$label} leaked a secret value.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function seedRecordsWithSecrets(): array
    {
        $providerKey = 'sk-live-'.Str::random(24);
        $connectorCredential = 'sk-mcp-'.Str::random(24);
        $toolCredential = 'Bearer sk-tool-'.Str::random(24);

        $this->secrets = [$providerKey, $connectorCredential, $toolCredential, 'senha-super-secreta'];

        $provider = AiProvider::factory()->create(['api_key' => $providerKey]);
        AiModel::factory()->defaultText()->for($provider, 'provider')->create();
        $realtimeModel = AiModel::factory()->defaultRealtime()->for($provider, 'provider')->create();

        $connector = McpServer::factory()->for($this->user)->connected()->create(['credential' => $connectorCredential]);
        $definition = McpToolDefinition::factory()->for($connector, 'server')->create();
        $mcpTool = Tool::factory()->mcp()->create(['mcp_tool_definition_id' => $definition->id, 'credential' => $connectorCredential]);

        $restTool = Tool::factory()->rest()->write()->create(['credential' => $toolCredential]);

        $conversation = Conversation::factory()->for($this->user)->create();
        $message = Message::factory()->for($conversation)->for($this->user)->fromAssistant()->create();

        $voiceSession = VoiceSession::factory()->for($this->user)->create(['ai_model_id' => $realtimeModel->id]);
        $task = Task::factory()->for($this->user)->running()->create([
            'voice_session_id' => $voiceSession->id,
            'conversation_id' => $voiceSession->conversation_id,
        ]);
        $visualization = Visualization::factory()->for($this->user)->create(['task_id' => $task->id]);
        $task->update(['visualization_id' => $visualization->id]);
        $agentExecution = AgentExecution::factory()->for($this->user)->completed()->create();
        $toolExecution = ToolExecution::factory()->create([
            'user_id' => $this->user->id,
            'tool_id' => $restTool->id,
            'agent_execution_id' => $agentExecution->id,
        ]);

        Memory::factory()->for($this->user)->confirmed()->create();

        $whatsappSecrets = ['wz-token-'.Str::random(24), 'wz-hmac-'.Str::random(32), 'wz-webhook-'.Str::random(37)];
        $this->secrets = [...$this->secrets, ...$whatsappSecrets];
        $whatsappSession = WhatsappSession::factory()->for($this->user)->create([
            'token' => $whatsappSecrets[0],
            'hmac_key' => $whatsappSecrets[1],
            'webhook_key' => $whatsappSecrets[2],
            'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::ERROR),
            'last_error' => 'Não foi possível falar com o serviço do WhatsApp.',
        ]);

        return [
            'conversation' => $conversation,
            'message' => $message,
            'task' => $task,
            'approval' => Approval::factory()->for($this->user)->pending()->create(['tool_id' => $restTool->id]),
            'connector' => $connector,
            'tool' => $mcpTool,
            'restTool' => $restTool,
            'agentExecution' => $agentExecution,
            'toolExecution' => $toolExecution,
            'notification' => Notification::factory()->for($this->user)->create(),
            'voice_session' => $voiceSession,
            'visualization' => $visualization,
            'whatsapp_session' => $whatsappSession,
            'user' => $this->user,
        ];
    }

    private function resolveParameters(string $uri): string
    {
        return preg_replace_callback('/\{(\w+)\??\}/', function (array $match): string {
            $this->assertArrayHasKey($match[1], $this->records, "No fixture for route parameter {$match[0]}.");

            return (string) $this->records[$match[1]]->getKey();
        }, $uri);
    }

    /**
     * Every broadcastable event of the app, built from secret-bearing fixtures.
     *
     * @return list<ShouldBroadcast>
     */
    private function broadcastableEvents(): array
    {
        $events = [];

        foreach (File::allFiles(app_path('Events')) as $file) {
            $class = 'App\\Events\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->implementsInterface(ShouldBroadcast::class)) {
                continue;
            }

            $events[] = $reflection->newInstanceArgs(array_map(
                fn ($parameter): mixed => $this->argumentFor($parameter->getType(), $parameter->getName()),
                $reflection->getConstructor()?->getParameters() ?? [],
            ));
        }

        return $events;
    }

    private function argumentFor(?\ReflectionType $type, string $name): mixed
    {
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'string';

        return match ($typeName) {
            User::class => $this->user,
            Conversation::class => $this->records['conversation'],
            Message::class => $this->records['message'],
            Task::class => $this->records['task'],
            Approval::class => $this->records['approval'],
            McpServer::class => $this->records['connector'],
            Tool::class => $this->records['restTool'],
            AgentExecution::class => $this->records['agentExecution'],
            ToolExecution::class => $this->records['toolExecution'],
            Notification::class => $this->records['notification'],
            VoiceSession::class => $this->records['voice_session'],
            Visualization::class => $this->records['visualization'],
            WhatsappSession::class => $this->records['whatsapp_session'],
            'int' => $name === 'messageId' ? $this->records['message']->id : 1,
            'bool' => true,
            default => 'idle',
        };
    }

    /**
     * The exact payload the broadcaster would serialize for the event.
     *
     * @return array<string, mixed>
     */
    private function broadcastPayload(ShouldBroadcast $event): array
    {
        $method = new ReflectionMethod(BroadcastEvent::class, 'getPayloadFromEvent');

        return $method->invoke(new BroadcastEvent($event), $event);
    }
}
