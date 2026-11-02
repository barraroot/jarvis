<?php

namespace App\Http\Resources;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An active agent as offered to the operator (US-7.4): the `@slug` picker and
 * the agent selector surface id, name, slug and role.
 *
 * @mixin Agent
 */
class AgentSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_default' => $this->is_default,
        ];
    }
}
