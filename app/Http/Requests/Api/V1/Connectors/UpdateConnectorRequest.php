<?php

namespace App\Http\Requests\Api\V1\Connectors;

use App\Models\McpServer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/connectors/{connector} — edit a personal connector (US-9.2).
 * A blank credential keeps the stored one.
 */
class UpdateConnectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var McpServer|null $connector */
        $connector = $this->route('connector');

        return $connector !== null && $this->user()->can('update', $connector);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var McpServer $connector */
        $connector = $this->route('connector');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:120',
                Rule::unique('mcp_servers', 'name')
                    ->when(
                        $connector->isGlobal(),
                        fn ($rule) => $rule->whereNull('user_id'),
                        fn ($rule) => $rule->where('user_id', $connector->user_id),
                    )
                    ->ignore($connector->id),
            ],
            'url' => ['sometimes', 'required', 'string', 'max:2048', StoreConnectorRequest::urlRule()],
            'credential' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
