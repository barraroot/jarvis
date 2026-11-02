<?php

namespace App\Http\Requests\Api\V1\Connectors;

use App\Models\McpServer;
use App\Support\McpUrlValidator;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/connectors — a personal MCP connector (US-9.2): name, HTTP(S)
 * URL (no stdio, no private hosts) and optional credential.
 */
class StoreConnectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', McpServer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('mcp_servers', 'name')->where('user_id', $this->user()->id),
            ],
            'url' => ['required', 'string', 'max:2048', self::urlRule()],
            'credential' => ['nullable', 'string', 'max:4096'],
        ];
    }

    /**
     * Shared URL rule (API and admin panel) backed by {@see McpUrlValidator}.
     */
    public static function urlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $error = app(McpUrlValidator::class)->errorFor(is_string($value) ? $value : null);

            if ($error !== null) {
                $fail($error);
            }
        };
    }
}
