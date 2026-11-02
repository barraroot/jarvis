<?php

namespace App\Http\Requests\Api\V1\Conversations;

use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/conversations/{conversation}/messages — a new user message (US-3.1).
 */
class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Conversation|null $conversation */
        $conversation = $this->route('conversation');

        return $conversation !== null && $this->user()->can('view', $conversation);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:8000'],
            'source' => ['required', Rule::in(['text', 'voice'])],
        ];
    }
}
