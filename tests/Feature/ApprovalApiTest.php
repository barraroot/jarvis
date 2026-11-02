<?php

namespace Tests\Feature;

use App\Events\Tool\ToolCompleted;
use App\Events\Tool\ToolStarted;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Models\Tool;
use App\Models\User;
use App\Tools\Contracts\JarvisTool;
use App\Tools\ToolContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ApprovalApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();

        $this->actingAs($this->user);
    }

    public function test_lists_only_own_pending_approvals(): void
    {
        $agent = Agent::factory()->create(['name' => 'Automation']);
        $execution = AgentExecution::factory()->for($this->user)->for($agent)->running()->create();

        $pending = Approval::factory()->for($this->user)->pending()->create([
            'tool_id' => Tool::factory()->write()->create()->id,
            'agent_execution_id' => $execution->id,
            'input' => ['customer_ids' => [42, 77]],
        ]);
        Approval::factory()->for($this->user)->denied()->create();
        Approval::factory()->for(User::factory())->pending()->create();

        $this->getJson('/api/v1/approvals?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.tool.identifier', $pending->tool->identifier)
            ->assertJsonPath('data.0.effect.is_write', true)
            ->assertJsonPath('data.0.effect.external', false)
            ->assertJsonPath('data.0.params.customer_ids', [42, 77])
            ->assertJsonPath('data.0.agent', 'Automation')
            ->assertJsonPath('data.0.expires_at', fn ($value) => $value !== null);

        // Without the filter, every own approval appears (including decided ones).
        $this->getJson('/api/v1/approvals')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_approve_endpoint_returns_409_when_not_pending(): void
    {
        Event::fake([ToolStarted::class, ToolCompleted::class, ToolFailed::class]);

        $decided = Approval::factory()->for($this->user)->approved()->create();

        $this->postJson("/api/v1/approvals/{$decided->id}/approve")
            ->assertStatus(409);

        // The owner can still approve a pending one; the tool runs once.
        $pending = Approval::factory()->for($this->user)->pending()->create([
            'tool_id' => $this->executableTool()->id,
        ]);

        $this->postJson("/api/v1/approvals/{$pending->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(ApprovalStatus::APPROVED, $pending->fresh()->status->slug);

        // Another user's approval is off limits.
        $foreign = Approval::factory()->for(User::factory())->pending()->create();

        $this->postJson("/api/v1/approvals/{$foreign->id}/approve")
            ->assertStatus(403);

        $this->assertTrue($foreign->fresh()->isPending());
    }

    public function test_deny_records_reason_and_expired_returns_409(): void
    {
        Event::fake([ToolStarted::class, ToolCompleted::class, ToolFailed::class]);

        $pending = Approval::factory()->for($this->user)->pending()->create();

        $this->postJson("/api/v1/approvals/{$pending->id}/deny", ['reason' => 'Cliente já pagou'])
            ->assertOk()
            ->assertJsonPath('data.status', 'denied')
            ->assertJsonPath('data.decision_reason', 'Cliente já pagou');

        $expired = Approval::factory()->for($this->user)->pending()->create([
            'requested_at' => now()->subDay(),
            'expires_at' => now()->subHour(),
        ]);

        $this->postJson("/api/v1/approvals/{$expired->id}/approve")
            ->assertStatus(409);

        // Denying with an overlong reason is rejected by validation.
        $other = Approval::factory()->for($this->user)->pending()->create();

        $this->postJson("/api/v1/approvals/{$other->id}/deny", ['reason' => str_repeat('a', 501)])
            ->assertStatus(422);
    }

    private function executableTool(): Tool
    {
        return Tool::factory()->readOnly()->create([
            'identifier' => 'test.api-echo',
            'handler_class' => ApiEchoInternalTool::class,
            'input_schema' => ['type' => 'object', 'properties' => []],
            'output_schema' => ['type' => 'object', 'properties' => []],
        ]);
    }
}

class ApiEchoInternalTool implements JarvisTool
{
    public function identifier(): string
    {
        return 'test.api-echo';
    }

    public function name(): string
    {
        return 'Echo de API';
    }

    public function description(): string
    {
        return 'Ferramenta executável para testes da API de aprovações.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function handle(array $input, ToolContext $context): array
    {
        return ['ok' => true];
    }
}
