<?php

namespace Tests\Feature;

use App\Models\AgentExecution;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Approval;
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
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * Project-description §4.1/§5: provider API keys, connector/tool credentials
 * and passwords never leave the backend — neither through any authenticated
 * `GET /api/v1/*` endpoint nor through any broadcast payload.
 */
class SecretsNeverLeakTest extends TestCase
{
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        config(['broadcasting.default' => 'null']);

        $this->user = User::factory()->withPreference()->create(['password' => 'senha-super-secreta']);
        $this->records = $this->seedRecordsWithSecrets();
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

        $connector = McpServer::factory()->for($this->user)->connected()->create(['credential' => $connectorCredential]);
        $definition = McpToolDefinition::factory()->for($connector, 'server')->create();
        $mcpTool = Tool::factory()->mcp()->create(['mcp_tool_definition_id' => $definition->id, 'credential' => $connectorCredential]);

        $restTool = Tool::factory()->rest()->write()->create(['credential' => $toolCredential]);

        $conversation = Conversation::factory()->for($this->user)->create();
        $message = Message::factory()->for($conversation)->for($this->user)->fromAssistant()->create();

        $task = Task::factory()->for($this->user)->running()->create();
        $agentExecution = AgentExecution::factory()->for($this->user)->completed()->create();
        $toolExecution = ToolExecution::factory()->create([
            'user_id' => $this->user->id,
            'tool_id' => $restTool->id,
            'agent_execution_id' => $agentExecution->id,
        ]);

        Memory::factory()->for($this->user)->confirmed()->create();

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
