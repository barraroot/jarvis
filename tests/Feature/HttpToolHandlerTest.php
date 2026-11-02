<?php

namespace Tests\Feature;

use App\Events\Tool\ToolCompleted;
use App\Events\Tool\ToolFailed;
use App\Events\Tool\ToolStarted;
use App\Models\ExecutionStatus;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\User;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class HttpToolHandlerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
    }

    public function test_sends_input_as_json_with_credential_header(): void
    {
        Event::fake([ToolStarted::class, ToolCompleted::class]);

        $tool = $this->restTool([
            'credential' => 'Bearer sekret-token',
            'output_schema' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']],
        ]);

        Http::fake([
            'https://erp.example/api/reminders' => Http::response(['ok' => true], 200),
        ]);

        $result = $this->executor()->execute($tool, ['customer_id' => 7], $this->context());

        $this->assertTrue($result->isCompleted());
        $this->assertSame(['ok' => true], $result->output);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://erp.example/api/reminders'
            && $request->method() === 'POST'
            && $request['customer_id'] === 7
            && $request->hasHeader('Authorization', 'Bearer sekret-token'));

        // A custom header declared in `input_schema.x-auth` replaces `Authorization`.
        Http::fake([
            'https://erp.example/api/reminders' => Http::response(['ok' => true], 200),
        ]);

        $custom = $this->restTool([
            'identifier' => 'rest.custom_header',
            'credential' => 'api-key-123',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['customer_id' => ['type' => 'integer']],
                'required' => ['customer_id'],
                'x-auth' => 'X-API-Key',
            ],
            'output_schema' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']],
        ]);

        $this->executor()->execute($custom, ['customer_id' => 8], $this->context());

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-API-Key', 'api-key-123')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_non_2xx_response_fails_execution(): void
    {
        Event::fake([ToolStarted::class, ToolFailed::class]);

        $tool = $this->restTool();

        Http::fake([
            'https://erp.example/api/reminders' => Http::response(['error' => 'boom'], 500),
        ]);

        $result = $this->executor()->execute($tool, ['customer_id' => 7], $this->context());

        $this->assertSame(ExecutionStatus::FAILED, $result->status);
        $this->assertStringContainsString('HTTP 500', $result->error);

        $this->assertDatabaseHas('tool_executions', [
            'tool_id' => $tool->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::FAILED),
        ]);
    }

    public function test_credential_is_not_persisted_in_execution_record(): void
    {
        Event::fake([ToolStarted::class, ToolCompleted::class]);

        $credential = 'Bearer super-secret-credential-9876';

        $tool = $this->restTool(['credential' => $credential]);

        Http::fake([
            'https://erp.example/api/reminders' => Http::response(['sent' => true], 200),
        ]);

        $result = $this->executor()->execute($tool, ['customer_id' => 42], $this->context());

        $this->assertTrue($result->isCompleted());

        $execution = ToolExecution::query()->firstOrFail();

        foreach ([$execution->input, $execution->output] as $payload) {
            $this->assertNotNull($payload, 'The execution record must carry the input/output payloads.');
            $this->assertStringNotContainsString($credential, (string) json_encode($payload));
        }
    }

    private function executor(): ToolExecutor
    {
        return app(ToolExecutor::class);
    }

    private function context(): ToolContext
    {
        return new ToolContext($this->user);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function restTool(array $attributes = []): Tool
    {
        return Tool::factory()->rest()->create([
            'identifier' => 'rest.send_reminder',
            'endpoint_url' => 'https://erp.example/api/reminders',
            'http_method' => 'POST',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['customer_id' => ['type' => 'integer']],
                'required' => ['customer_id'],
            ],
            'output_schema' => ['type' => 'object', 'properties' => []],
            'is_write' => false,
            'is_sensitive' => false,
            ...$attributes,
        ]);
    }
}
