<?php

namespace App\Http\Resources;

use App\Models\AiModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of an AI model (never exposes provider credentials).
 *
 * @mixin AiModel
 */
class AiModelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider->slug,
            'is_default' => $this->is_default_text,
        ];
    }
}
