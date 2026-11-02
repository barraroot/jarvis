<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ToolCatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $toolTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->toolTypeId = DB::table('tool_types')->insertGetId([
            'name' => 'MCP',
            'slug' => 'mcp',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_tool_identifier_is_unique(): void
    {
        $this->insertTool('erp.list_overdue_customers');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function (): void {
            $this->insertTool('erp.list_overdue_customers');
        });
    }

    public function test_mcp_tool_definition_links_to_at_most_one_tool(): void
    {
        $definitionId = $this->insertMcpToolDefinition('list_customers');

        $this->insertTool('erp.list_customers', ['mcp_tool_definition_id' => $definitionId]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($definitionId): void {
            $this->insertTool('erp.list_customers_copy', ['mcp_tool_definition_id' => $definitionId]);
        });
    }

    public function test_tools_default_to_write_and_sensitive(): void
    {
        $toolId = $this->insertTool('erp.delete_customer');

        $tool = DB::table('tools')->find($toolId);

        $this->assertTrue((bool) $tool->is_write);
        $this->assertTrue((bool) $tool->is_sensitive);
        $this->assertSame(30, (int) $tool->timeout_seconds);
    }

    public function test_only_one_default_agent_allowed(): void
    {
        $this->insertAgent('personal-assistant', ['is_default' => true]);
        $this->insertAgent('research');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function (): void {
            $this->insertAgent('developer', ['is_default' => true]);
        });
    }

    public function test_mcp_tool_definition_name_is_unique_per_server(): void
    {
        $this->insertMcpToolDefinition('list_customers');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function (): void {
            $this->insertMcpToolDefinition('list_customers');
        });
    }

    public function test_agent_tool_pivot_rejects_duplicated_pairs(): void
    {
        $agentId = $this->insertAgent('research');
        $toolId = $this->insertTool('web.search');

        DB::table('agent_tool')->insert(['agent_id' => $agentId, 'tool_id' => $toolId, 'created_at' => now()]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($agentId, $toolId): void {
            DB::table('agent_tool')->insert(['agent_id' => $agentId, 'tool_id' => $toolId, 'created_at' => now()]);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertTool(string $identifier, array $overrides = []): int
    {
        return DB::table('tools')->insertGetId(array_merge([
            'tool_type_id' => $this->toolTypeId,
            'identifier' => $identifier,
            'name' => $identifier,
            'description' => 'Tool '.$identifier,
            'input_schema' => json_encode(['type' => 'object']),
            'output_schema' => json_encode(['type' => 'object']),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertAgent(string $slug, array $overrides = []): int
    {
        return DB::table('agents')->insertGetId(array_merge([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'description' => 'Agent '.$slug,
            'objective' => 'Objective',
            'instructions' => 'Instructions',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertMcpToolDefinition(string $name): int
    {
        $serverId = DB::table('mcp_servers')->where('name', 'erp')->value('id');

        if ($serverId === null) {
            $statusId = DB::table('connector_statuses')->insertGetId([
                'name' => 'Connected',
                'slug' => 'connected',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $serverId = DB::table('mcp_servers')->insertGetId([
                'connector_status_id' => $statusId,
                'name' => 'erp',
                'url' => 'https://erp.example.com/mcp',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return DB::table('mcp_tool_definitions')->insertGetId([
            'mcp_server_id' => $serverId,
            'name' => $name,
            'input_schema' => json_encode(['type' => 'object']),
            'discovered_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
