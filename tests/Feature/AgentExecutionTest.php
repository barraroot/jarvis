<?php

namespace Tests\Feature;

use App\Models\AgentExecution;
use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Models\ExecutionStatus;
use App\Models\ToolExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AgentExecutionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_ancestor_agent_ids_walks_delegation_chain(): void
    {
        $root = AgentExecution::factory()->running()->create();
        $child = AgentExecution::factory()->childOf($root)->running()->create();
        $grandchild = AgentExecution::factory()->childOf($child)->create();

        $this->assertSame([], $root->ancestorAgentIds());
        $this->assertSame([$root->agent_id], $child->ancestorAgentIds());
        $this->assertSame([$child->agent_id, $root->agent_id], $grandchild->ancestorAgentIds());

        $this->assertSame(2, $grandchild->depth);
        $this->assertTrue($grandchild->parent->is($child));
        $this->assertTrue($root->children->contains($child));
        $this->assertSame($root->user_id, $grandchild->user_id);
        $this->assertSame(ExecutionStatus::RUNNING, $child->status->slug);
    }

    public function test_approval_state_helpers_and_tool_execution_link(): void
    {
        $pending = Approval::factory()->pending()->create();
        $approved = Approval::factory()->approved()->create();
        $expired = Approval::factory()->expired()->create();
        $overdue = Approval::factory()->pending()->create(['expires_at' => now()->subMinute()]);

        $this->assertTrue($pending->isPending());
        $this->assertFalse($pending->isExpired());
        $this->assertFalse($approved->isPending());
        $this->assertFalse($approved->isExpired());
        $this->assertTrue($expired->isExpired());
        $this->assertTrue($overdue->isExpired());
        $this->assertTrue($approved->decidedBy->is($approved->user));
        $this->assertSame(ApprovalStatus::APPROVED, $approved->status->slug);

        $execution = ToolExecution::factory()
            ->for($approved, 'approval')
            ->for($approved->tool)
            ->for($approved->user)
            ->create();

        $this->assertTrue($approved->toolExecution->is($execution));
        $this->assertTrue($execution->approval->is($approved));
        $this->assertSame(['customer_id' => $approved->input['customer_id']], $approved->input);
    }
}
