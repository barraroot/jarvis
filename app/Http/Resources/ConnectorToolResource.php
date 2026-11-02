<?php

namespace App\Http\Resources;

use App\Models\McpToolDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tool discovered on a connector (US-9.3) with its catalogue classification.
 * `id` is the catalogue tool id used by the classification route.
 *
 * @mixin McpToolDefinition
 */
class ConnectorToolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->tool?->id,
            'definition_id' => $this->id,
            'name' => $this->name,
            'identifier' => $this->tool?->identifier,
            'description' => $this->description,
            'input_schema' => $this->input_schema,
            'is_available' => $this->is_available,
            'is_write' => (bool) $this->tool?->is_write,
            'is_sensitive' => (bool) $this->tool?->is_sensitive,
            'is_active' => (bool) $this->tool?->is_active,
            'discovered_at' => $this->discovered_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
