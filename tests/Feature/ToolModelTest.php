<?php

namespace Tests\Feature;

use App\Models\Tool;
use App\Models\ToolType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ToolModelTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_requires_approval_when_write_or_sensitive(): void
    {
        $readOnly = Tool::factory()->readOnly()->create();
        $write = Tool::factory()->write()->create();
        $sensitive = Tool::factory()->sensitive()->create();
        $both = Tool::factory()->write()->sensitive()->create();

        $this->assertFalse($readOnly->requiresApproval());
        $this->assertTrue($write->requiresApproval());
        $this->assertTrue($sensitive->requiresApproval());
        $this->assertTrue($both->requiresApproval());

        $this->assertEqualsCanonicalizing([$readOnly->id, $sensitive->id], Tool::readOnly()->pluck('id')->all());
    }

    public function test_credential_is_encrypted_and_hidden(): void
    {
        $tool = Tool::factory()->rest()->create(['credential' => 'Bearer secret-token-9999']);

        $stored = DB::table('tools')->where('id', $tool->id)->value('credential');

        $this->assertStringNotContainsString('secret-token', $stored);
        $this->assertSame('Bearer secret-token-9999', $tool->fresh()->credential);
        $this->assertArrayNotHasKey('credential', $tool->fresh()->toArray());
        $this->assertSame(ToolType::REST, $tool->type->slug);
    }

    public function test_mcp_tool_links_to_its_definition_and_defaults_to_write_and_sensitive(): void
    {
        $tool = Tool::factory()->mcp()->create();

        $this->assertSame(ToolType::MCP, $tool->type->slug);
        $this->assertTrue($tool->is_write);
        $this->assertTrue($tool->is_sensitive);
        $this->assertTrue($tool->mcpToolDefinition->tool->is($tool));
        $this->assertIsArray($tool->input_schema);
    }
}
