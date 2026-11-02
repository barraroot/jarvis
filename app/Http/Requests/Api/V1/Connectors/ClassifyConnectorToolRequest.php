<?php

namespace App\Http\Requests\Api\V1\Connectors;

use App\Models\McpServer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/connectors/{connector}/tools/{tool} — explicit classification
 * of a discovered tool (US-9.3), only by the owner of a personal connector.
 */
class ClassifyConnectorToolRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var McpServer|null $connector */
        $connector = $this->route('connector');

        return $connector !== null && $this->user()->can('classifyTools', $connector);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_write' => ['sometimes', 'boolean'],
            'is_sensitive' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
