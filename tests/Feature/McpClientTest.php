<?php

namespace Tests\Feature;

use App\Exceptions\InvalidMcpUrlException;
use App\Exceptions\McpConnectionException;
use App\Models\McpServer;
use App\Services\McpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMcpServer;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class McpClientTest extends TestCase
{
    use FakesMcpServer;
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        config(['services.mcp.allow_private_hosts' => false]);
    }

    public function test_lists_tools_from_fake_server(): void
    {
        $server = McpServer::factory()->make([
            'url' => 'https://erp.example.com/mcp',
            'credential' => 'secret-token-1234',
        ]);

        $this->fakeMcpServer('https://erp.example.com/mcp', [
            $this->mcpToolPayload('list_overdue_customers', 'Clientes com pagamentos atrasados'),
            ['name' => 'ping_erp', 'inputSchema' => []],
        ], fn (string $name, array $arguments): array => [
            'content' => [['type' => 'text', 'text' => 'ignored']],
            'structuredContent' => ['tool' => $name, 'arguments' => $arguments],
        ]);

        $client = McpClient::for($server);
        $tools = $client->listTools();

        $this->assertCount(2, $tools);
        $this->assertSame('list_overdue_customers', $tools[0]['name']);
        $this->assertSame('Clientes com pagamentos atrasados', $tools[0]['description']);
        $this->assertSame('string', $tools[0]['input_schema']['properties']['query']['type']);
        $this->assertNull($tools[0]['output_schema']);
        $this->assertSame('ping_erp', $tools[1]['description']);
        $this->assertSame(['type' => 'object'], $tools[1]['input_schema']);

        $output = $client->call('list_overdue_customers', ['query' => 'atrasados']);

        $this->assertSame(['tool' => 'list_overdue_customers', 'arguments' => ['query' => 'atrasados']], $output);

        Http::assertSent(fn (Request $request): bool => ($request->data()['method'] ?? null) === 'tools/list'
            && $request->hasHeader('Authorization', 'Bearer secret-token-1234'));
    }

    public function test_rejects_non_http_and_private_urls(): void
    {
        $rejected = [
            'stdio://npx some-server',
            'npx -y @modelcontextprotocol/server-filesystem',
            'ftp://files.example.com/mcp',
            'http://localhost:8080/mcp',
            'http://api.localhost/mcp',
            'http://127.0.0.1/mcp',
            'https://10.0.0.5/mcp',
            'https://172.16.4.2/mcp',
            'https://172.31.255.255/mcp',
            'https://192.168.1.10/mcp',
            'http://[::1]/mcp',
        ];

        foreach ($rejected as $url) {
            try {
                McpClient::for(McpServer::factory()->make(['url' => $url]));
                $this->fail("URL [{$url}] should have been rejected.");
            } catch (InvalidMcpUrlException) {
                $this->addToAssertionCount(1);
            }
        }

        // Public hosts (including 172.32/12 outside the private block) are accepted.
        $this->assertInstanceOf(McpClient::class, McpClient::for(McpServer::factory()->make(['url' => 'https://172.32.0.1/mcp'])));
        $this->assertInstanceOf(McpClient::class, McpClient::for(McpServer::factory()->make(['url' => 'http://erp.example.com/mcp'])));

        // Local development may opt in to private hosts, but never to stdio.
        config(['services.mcp.allow_private_hosts' => true]);

        $this->assertInstanceOf(McpClient::class, McpClient::for(McpServer::factory()->make(['url' => 'http://localhost:8080/mcp'])));

        $this->expectException(InvalidMcpUrlException::class);

        McpClient::for(McpServer::factory()->make(['url' => 'stdio://npx some-server']));
    }

    public function test_connection_error_hides_credential(): void
    {
        $credential = 'super-secret-credential-9876';

        Http::fake([
            'https://erp.example.com/mcp' => fn () => throw new ConnectionException("cURL error 7: Failed to connect (token {$credential})"),
        ]);

        $server = McpServer::factory()->make([
            'url' => 'https://erp.example.com/mcp',
            'credential' => $credential,
        ]);

        try {
            McpClient::for($server)->listTools();
            $this->fail('A connection error was expected.');
        } catch (McpConnectionException $exception) {
            $this->assertStringContainsString('Failed to connect', $exception->getMessage());
            $this->assertStringNotContainsString($credential, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }
}
