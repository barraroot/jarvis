<?php

namespace App\Jobs;

use App\Exceptions\InvalidMcpUrlException;
use App\Exceptions\McpConnectionException;
use App\Models\ConnectorStatus;
use App\Models\McpServer;
use App\Models\McpToolDefinition;
use App\Models\Tool;
use App\Models\ToolType;
use App\Services\ConnectorStatusService;
use App\Services\McpClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Discovers a connector's tools (US-9.3): upserts `mcp_tool_definitions`,
 * creates catalogue `tools` for new ones (write + sensitive + inactive until
 * classified), deactivates the ones the server stopped publishing and records
 * the connector status (`connected` / `error`).
 */
class SyncMcpServerTools implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly McpServer $server) {}

    public function handle(ConnectorStatusService $statuses): void
    {
        $server = McpServer::query()->with('status')->find($this->server->getKey());

        if ($server === null) {
            return;
        }

        try {
            $tools = McpClient::for($server)->listTools();
        } catch (McpConnectionException|InvalidMcpUrlException $exception) {
            $statuses->record($server, ConnectorStatus::ERROR, $exception->getMessage());

            return;
        }

        DB::transaction(function () use ($server, $tools): void {
            $seenAt = now();
            $seenNames = [];

            foreach ($tools as $tool) {
                $seenNames[] = $tool['name'];

                $this->syncDefinition($server, $tool, $seenAt);
            }

            $removed = $server->toolDefinitions()
                ->whereNotIn('name', $seenNames)
                ->where('is_available', true)
                ->get();

            foreach ($removed as $definition) {
                $definition->update(['is_available' => false]);
                $definition->tool?->update(['is_active' => false]);
            }

            $server->forceFill(['last_synced_at' => $seenAt])->save();
        });

        $statuses->record($server, ConnectorStatus::CONNECTED);
    }

    /**
     * @param  array{name: string, description: string, input_schema: array<string, mixed>, output_schema: array<string, mixed>|null}  $tool
     */
    private function syncDefinition(McpServer $server, array $tool, \DateTimeInterface $seenAt): void
    {
        /** @var McpToolDefinition $definition */
        $definition = $server->toolDefinitions()->firstOrNew(['name' => $tool['name']]);

        $definition->fill([
            'description' => $tool['description'],
            'input_schema' => $tool['input_schema'],
            'output_schema' => $tool['output_schema'],
            'is_available' => true,
            'last_seen_at' => $seenAt,
        ]);
        $definition->discovered_at ??= $seenAt;
        $definition->save();

        $catalogTool = Tool::query()->withTrashed()->where('mcp_tool_definition_id', $definition->id)->first();

        if ($catalogTool !== null) {
            if (! $catalogTool->trashed()) {
                $catalogTool->update([
                    'description' => $tool['description'],
                    'input_schema' => $tool['input_schema'],
                    'output_schema' => $tool['output_schema'] ?? ['type' => 'object'],
                ]);
            }

            return;
        }

        Tool::query()->create([
            'tool_type_id' => ToolType::idFor(ToolType::MCP),
            'mcp_tool_definition_id' => $definition->id,
            'identifier' => $this->identifierFor($server, $tool['name']),
            'name' => $tool['name'],
            'description' => $tool['description'],
            'input_schema' => $tool['input_schema'],
            'output_schema' => $tool['output_schema'] ?? ['type' => 'object'],
            'is_write' => true,
            'is_sensitive' => true,
            'is_active' => false,
        ]);
    }

    /**
     * `mcp.<server_slug>.<name>`; a global and a personal connector sharing a
     * name get the server id appended to keep identifiers unique.
     */
    private function identifierFor(McpServer $server, string $name): string
    {
        $identifier = "mcp.{$server->slug()}.{$name}";

        if (Tool::query()->withTrashed()->where('identifier', $identifier)->exists()) {
            return "mcp.{$server->slug()}_{$server->id}.{$name}";
        }

        return $identifier;
    }
}
