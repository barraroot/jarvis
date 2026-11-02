<?php

namespace App\Http\Resources;

use App\Models\McpServer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An MCP connector as seen by an operator (US-9.1/9.2/9.4): scope, status,
 * tool counts, last sync/check and error. The credential is never returned —
 * only whether one is set.
 *
 * @mixin McpServer
 */
class ConnectorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'scope' => $this->isGlobal() ? 'global' : 'personal',
            'is_active' => $this->is_active,
            'status' => $this->status->slug,
            'has_credential' => filled($this->credential),
            'tools_count' => (int) ($this->tools_count ?? 0),
            'active_tools_count' => (int) ($this->active_tools_count ?? 0),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'can_manage' => $request->user()?->can('update', $this->resource) ?? false,
            'can_classify' => $request->user()?->can('classifyTools', $this->resource) ?? false,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
