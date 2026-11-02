<?php

namespace App\Http\Requests\Api\V1\Memories;

use App\Models\Memory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/memories/{memory} — content edit; the embedding is regenerated
 * by the service (US-6.4).
 */
class UpdateMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Memory|null $memory */
        $memory = $this->route('memory');

        return $memory !== null && $this->user()->can('update', $memory);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
