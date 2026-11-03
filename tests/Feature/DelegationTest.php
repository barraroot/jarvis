<?php

namespace Tests\Feature;

use App\Ai\Agents\RuntimeAgent;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Conversation;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\User;
use App\Services\DelegationService;
use App\Tools\ToolContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class DelegationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $provider = AiProvider::factory()->create();
        AiModel::factory()->defaultText()->for($provider, 'provider')->create();

        $this->user = User::factory()->create();
    }

    public function test_delegation_beyond_max_depth_is_blocked(): void
    {
        Event::fake();

        $origin = Agent::factory()->create(['max_delegation_depth' => 1]);
        $target = Agent::factory()->create();

        $parent = AgentExecution::factory()->running()->for($this->user)->for($origin)->create(['depth' => 1]);

        $result = app(DelegationService::class)->delegate(
            $target,
            'Execute uma subtarefa.',
            new ToolContext($this->user, agentExecution: $parent),
        );

        $this->assertStringContainsString('profundidade máxima', $result);
        $this->assertStringContainsString('trusted="false"', $result);
        $this->assertSame(1, AgentExecution::query()->count());
    }

    public function test_cycle_is_detected_and_blocked(): void
    {
        Event::fake();

        $agentA = Agent::factory()->create(['slug' => 'agent-a']);
        $agentB = Agent::factory()->create(['slug' => 'agent-b']);

        $root = AgentExecution::factory()->running()->for($this->user)->for($agentA)->create(['depth' => 0]);
        $child = AgentExecution::factory()->running()->childOf($root)->for($agentB)->create();

        // B tries to delegate back to A: A is an ancestor of B's execution.
        $result = app(DelegationService::class)->delegate(
            $agentA,
            'Execute uma subtarefa.',
            new ToolContext($this->user, agentExecution: $child),
        );

        $this->assertStringContainsString('ciclo', $result);
        $this->assertSame(2, AgentExecution::query()->count());
    }

    public function test_inactive_target_is_rejected(): void
    {
        Event::fake();

        $origin = Agent::factory()->create();
        $target = Agent::factory()->inactive()->create();

        $parent = AgentExecution::factory()->running()->for($this->user)->for($origin)->create(['depth' => 0]);

        $result = app(DelegationService::class)->delegate(
            $target,
            'Execute uma subtarefa.',
            new ToolContext($this->user, agentExecution: $parent),
        );

        $this->assertStringContainsString('inativo', $result);
        $this->assertSame(1, AgentExecution::query()->count());
    }

    public function test_child_execution_links_parent_and_depth(): void
    {
        Event::fake();

        $origin = Agent::factory()->create();
        $target = Agent::factory()->create();

        $conversation = Conversation::factory()->for($this->user)->create();
        $assistantMessage = Message::factory()->for($conversation)->for($this->user)->fromAssistant()->create();

        $parent = AgentExecution::factory()->running()->for($this->user)->for($origin)->create([
            'depth' => 0,
            'conversation_id' => $conversation->id,
            'message_id' => $assistantMessage->id,
        ]);

        Ai::fakeAgent(RuntimeAgent::class, function (string $prompt, mixed $attachments, mixed $provider, string $model): mixed {
            return new TextResponse('Subtarefa concluída.', new TextUsage(20, 8), new Meta($provider->name(), $model));
        });

        $result = app(DelegationService::class)->delegate(
            $target,
            'Execute uma subtarefa.',
            new ToolContext($this->user, agentExecution: $parent),
        );

        // The child's answer reaches the parent model as delimited, untrusted data.
        $this->assertStringContainsString('trusted="false">Subtarefa concluída.</tool_result>', $result);

        $child = AgentExecution::query()->where('parent_agent_execution_id', $parent->id)->firstOrFail();

        $this->assertSame($target->id, $child->agent_id);
        $this->assertSame(1, $child->depth);
        $this->assertSame($this->user->id, $child->user_id);
        $this->assertSame($conversation->id, $child->conversation_id);
        $this->assertSame($assistantMessage->id, $child->message_id);
        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::COMPLETED), $child->execution_status_id);
    }

    public function test_child_failure_is_returned_to_parent_as_tool_result(): void
    {
        Event::fake();

        $origin = Agent::factory()->create();
        $target = Agent::factory()->create();

        $parent = AgentExecution::factory()->running()->for($this->user)->for($origin)->create(['depth' => 0]);

        Ai::fakeAgent(RuntimeAgent::class, function (): never {
            throw new RuntimeException('Falha simulada no agente filho.');
        });

        $result = app(DelegationService::class)->delegate(
            $target,
            'Execute uma subtarefa.',
            new ToolContext($this->user, agentExecution: $parent),
        );

        $this->assertStringContainsString('Falha simulada no agente filho.', $result);
        $this->assertStringContainsString('trusted="false"', $result);

        $child = AgentExecution::query()->where('parent_agent_execution_id', $parent->id)->firstOrFail();
        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::FAILED), $child->execution_status_id);

        // The parent run is untouched: the failure is just a tool result.
        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::RUNNING), $parent->fresh()->execution_status_id);
    }
}
