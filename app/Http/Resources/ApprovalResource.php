<?php

namespace App\Http\Resources;

use App\Models\Approval;
use App\Models\ToolType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An approval request as seen by its owner (US-8.3): tool, effect
 * classification, parameters, requesting agent and the expiry countdown.
 *
 * @mixin Approval
 */
class ApprovalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->slug,
            'tool' => [
                'identifier' => $this->tool->identifier,
                'name' => $this->tool->name,
                'description' => $this->tool->description,
            ],
            'effect' => [
                'is_write' => $this->tool->is_write,
                'is_sensitive' => $this->tool->is_sensitive,
                'external' => in_array($this->tool->type->slug, [ToolType::REST, ToolType::WEBHOOK, ToolType::MCP], true),
            ],
            'params' => $this->input,
            'agent' => $this->whenLoaded('agentExecution', fn (): ?string => $this->agentExecution?->agent?->name),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_reason' => $this->decision_reason,
        ];
    }
}
