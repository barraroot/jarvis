<?php

namespace App\Tools;

use App\Models\Tool;
use App\Support\Settings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Executes `rest` and `webhook` catalogue tools (US-8.1/8.2): JSON body = the
 * validated input, credential sent as a header (`Authorization` by default, or
 * the custom header name declared in `input_schema.x-auth`), timeout from the
 * tool (or the global setting). Non-2xx responses fail the execution.
 */
class HttpToolHandler
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(Tool $tool, array $input, ToolContext $context): array
    {
        if (blank($tool->endpoint_url)) {
            throw new RuntimeException("Tool [{$tool->identifier}] has no endpoint URL.");
        }

        $method = strtoupper($tool->http_method ?: 'POST');
        $timeoutSeconds = $tool->timeout_seconds > 0
            ? $tool->timeout_seconds
            : $this->settings->toolTimeoutSeconds();

        $request = Http::acceptJson()->timeout($timeoutSeconds);

        if (filled($tool->credential)) {
            $request->withHeader($this->credentialHeader($tool), $tool->credential);
        }

        $response = $request->send($method, $tool->endpoint_url, ['json' => $input]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Tool [{$tool->identifier}] got HTTP {$response->status()} from {$tool->endpoint_url}.",
            );
        }

        return $this->parseOutput($response);
    }

    /**
     * The header carrying the credential: `input_schema.x-auth` names a custom
     * one (e.g. `X-API-Key`); otherwise the standard `Authorization` header.
     */
    private function credentialHeader(Tool $tool): string
    {
        $custom = $tool->input_schema['x-auth'] ?? null;

        return is_string($custom) && filled($custom) ? $custom : 'Authorization';
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOutput(Response $response): array
    {
        $body = $response->json();

        if (is_array($body)) {
            return $body;
        }

        if (blank($response->body())) {
            return [];
        }

        return ['body' => $response->body()];
    }
}
