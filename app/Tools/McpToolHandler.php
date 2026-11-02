<?php

namespace App\Tools;

use App\Models\Tool;
use App\Services\McpClient;
use App\Support\Settings;
use RuntimeException;

/**
 * Executes `mcp` catalogue tools (US-9.3, US-8.2): calls the remote tool on
 * its connector through {@see McpClient} with the tool timeout.
 */
class McpToolHandler
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(Tool $tool, array $input, ToolContext $context): array
    {
        $definition = $tool->mcpToolDefinition;
        $server = $definition?->server;

        if ($definition === null || $server === null) {
            throw new RuntimeException("Tool [{$tool->identifier}] has no MCP connector.");
        }

        if (! $server->is_active || ! $definition->is_available) {
            throw new RuntimeException("Tool [{$tool->identifier}] is unavailable on its MCP connector.");
        }

        if (! $server->isGlobal() && $server->user_id !== $context->user->id) {
            throw new RuntimeException("Tool [{$tool->identifier}] belongs to another user's connector.");
        }

        $timeoutSeconds = $tool->timeout_seconds > 0
            ? $tool->timeout_seconds
            : $this->settings->toolTimeoutSeconds();

        $client = McpClient::for($server)->withTimeout((float) $timeoutSeconds);

        try {
            return $client->call($definition->name, $input);
        } finally {
            $client->disconnect();
        }
    }
}
