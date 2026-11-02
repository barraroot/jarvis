<?php

namespace App\Http\Requests\Api\V1\Memories;

use App\Models\Memory;
use App\Models\MemoryCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/memories — an explicit new memory, always `confirmed` (US-6.4).
 */
class StoreMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Memory::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:2000'],
            'category' => ['required', Rule::in([
                MemoryCategory::PREFERENCE,
                MemoryCategory::PROJECT,
                MemoryCategory::CONTEXT,
                MemoryCategory::LEARNING,
                MemoryCategory::FACT,
            ])],
        ];
    }
}
