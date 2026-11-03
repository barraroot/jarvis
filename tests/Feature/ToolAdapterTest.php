<?php

namespace Tests\Feature;

use App\Events\Assistant\AssistantStateChanged;
use App\Events\NotificationCreated;
use App\Models\Approval;
use App\Models\Tool;
use App\Models\User;
use App\Services\ApprovalService;
use App\Support\ToolName;
use App\Tools\ToolAdapter;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool as SdkTool;
use Laravel\Ai\Tools\Request;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ToolAdapterTest extends TestCase
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

    public function test_write_tool_is_approvable(): void
    {
        $tool = Tool::factory()->write()->create([
            'input_schema' => [
                'type' => 'object',
                'properties' => ['customer_id' => ['type' => 'integer']],
                'required' => ['customer_id'],
            ],
        ]);

        $adapter = $this->adapterFor($tool);

        $this->assertInstanceOf(SdkTool::class, $adapter);
        $this->assertInstanceOf(Approvable::class, $adapter);
        $this->assertSame(ToolName::forProvider($tool->identifier), $adapter->name());
        $this->assertSame($tool->description, $adapter->description());

        Event::fake([AssistantStateChanged::class, NotificationCreated::class]);

        $approval = $adapter->shouldRequestApproval(new Request(['customer_id' => 42]));

        $this->assertNotNull($approval);
        $this->assertNotNull($approval->reason);

        $record = Approval::query()->firstOrFail();

        $this->assertSame($this->user->id, $record->user_id);
        $this->assertSame($tool->id, $record->tool_id);
        $this->assertSame(['customer_id' => 42], $record->input);

        // Read-only tools bypass the approval gate.
        $readOnly = Tool::factory()->readOnly()->create();

        $this->assertNull($this->adapterFor($readOnly)->shouldRequestApproval(new Request([])));
    }

    public function test_schema_maps_json_schema_to_sdk_types(): void
    {
        $tool = Tool::factory()->create([
            'input_schema' => [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string', 'minLength' => 3]],
                'required' => ['query'],
            ],
        ]);

        $types = $this->adapterFor($tool)->schema(new JsonSchemaTypeFactory);

        $this->assertArrayHasKey('query', $types);
        $this->assertInstanceOf(Type::class, $types['query']);
        $this->assertSame('string', $types['query']->toArray()['type']);
    }

    private function adapterFor(Tool $tool): ToolAdapter
    {
        return new ToolAdapter(
            $tool,
            app(ToolExecutor::class),
            app(ApprovalService::class),
            new ToolContext($this->user),
        );
    }
}
