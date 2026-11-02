<?php

namespace App\Services;

use App\Exceptions\InvalidMcpUrlException;
use App\Exceptions\McpConnectionException;
use App\Models\McpServer;
use App\Support\McpUrlValidator;
use Laravel\Ai\Tools\McpTool;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\Primitives\Tool as McpClientTool;
use Laravel\Mcp\WebClient;
use Throwable;

/**
 * HTTP/SSE client for an MCP connector (US-9.1/9.2/9.3), built on the
 * `laravel/mcp` web client that `laravel/ai` wraps as `McpTool`. The
 * credential travels as `Authorization: Bearer`; every failure surfaces as
 * {@see McpConnectionException} without the credential in the message.
 */
class McpClient
{
    private ?WebClient $client = null;

    public function __construct(
        private readonly string $url,
        private readonly ?string $credential = null,
        private float $timeoutSeconds = 15.0,
    ) {}

    /**
     * Build the client for a connector, validating its URL first.
     *
     * @throws InvalidMcpUrlException
     */
    public static function for(McpServer $server): static
    {
        app(McpUrlValidator::class)->assertValid($server->url);

        return new static(
            $server->url,
            $server->credential,
            (float) config('services.mcp.timeout_seconds', 15),
        );
    }

    public function withTimeout(float $seconds): static
    {
        $this->timeoutSeconds = $seconds;
        $this->client?->withTimeout($seconds);

        return $this;
    }

    /**
     * Tools published by the server.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>, output_schema: array<string, mixed>|null}>
     *
     * @throws McpConnectionException
     */
    public function listTools(): array
    {
        return $this->guard(fn (): array => $this->client()->tools()
            ->map(fn (McpClientTool $tool): array => [
                'name' => $tool->name,
                'description' => (new McpTool($tool))->description(),
                'input_schema' => $tool->inputSchema === [] ? ['type' => 'object'] : $tool->inputSchema,
                'output_schema' => $tool->outputSchema,
            ])
            ->values()
            ->all());
    }

    /**
     * Execute a remote tool. Structured content is returned as-is; plain text
     * results come back as `['text' => ...]`. Remote tool errors throw.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws McpConnectionException
     */
    public function call(string $name, array $input = []): array
    {
        $result = $this->guard(fn () => $this->client()->callTool($name, $input));

        if ($result->isError) {
            throw McpConnectionException::from(
                "A ferramenta MCP [{$name}] retornou erro: ".($result->text() ?: 'erro desconhecido').'.',
                $this->credential,
            );
        }

        if ($result->structuredContent !== null) {
            return $result->structuredContent;
        }

        return ['text' => $result->text()];
    }

    /**
     * Lightweight health probe (US-9.4): handshake plus JSON-RPC `ping`.
     *
     * @throws McpConnectionException
     */
    public function ping(): void
    {
        $this->guard(function (): void {
            $this->client()->ping();
        });
    }

    public function disconnect(): void
    {
        try {
            $this->client?->disconnect();
        } catch (Throwable) {
            //
        }

        $this->client = null;
    }

    private function client(): WebClient
    {
        if ($this->client === null) {
            $this->client = Client::web($this->url)->withTimeout($this->timeoutSeconds);

            if (filled($this->credential)) {
                $this->client->withToken($this->credential);
            }
        }

        return $this->client;
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     *
     * @throws McpConnectionException
     */
    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (McpConnectionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw McpConnectionException::from(
                'Falha ao comunicar com o conector MCP: '.$exception->getMessage(),
                $this->credential,
            );
        }
    }
}
