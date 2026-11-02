<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes a streamable-HTTP MCP server with `Http::fake`: answers the legacy
 * `initialize` handshake (rejecting `server/discover`), `ping`, `tools/list`
 * and `tools/call`.
 */
trait FakesMcpServer
{
    /**
     * @param  list<array<string, mixed>>  $tools  MCP tool payloads (`name`, `description`, `inputSchema`, ...).
     * @param  (Closure(string, array<string, mixed>): array<string, mixed>)|null  $onCall  Returns the `tools/call` result.
     */
    protected function fakeMcpServer(string $url, array $tools = [], ?Closure $onCall = null): void
    {
        Http::fake([
            $url => function (Request $request) use ($tools, $onCall) {
                $payload = $request->data();
                $id = $payload['id'] ?? null;
                $method = $payload['method'] ?? '';

                if ($id === null) {
                    return Http::response('', 202);
                }

                $result = match ($method) {
                    'initialize' => [
                        'protocolVersion' => '2025-11-25',
                        'capabilities' => ['tools' => new \stdClass],
                        'serverInfo' => ['name' => 'Fake MCP', 'version' => '1.0.0'],
                    ],
                    'ping' => new \stdClass,
                    'tools/list' => ['tools' => $tools],
                    'tools/call' => $onCall !== null
                        ? $onCall($payload['params']['name'] ?? '', $payload['params']['arguments'] ?? [])
                        : ['content' => [['type' => 'text', 'text' => 'ok']]],
                    default => null,
                };

                if ($result === null) {
                    return Http::response([
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'error' => ['code' => -32601, 'message' => 'Method not found'],
                    ]);
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mcpToolPayload(string $name, ?string $description = null): array
    {
        return [
            'name' => $name,
            'description' => $description ?? "Tool {$name}",
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string']],
            ],
        ];
    }
}
