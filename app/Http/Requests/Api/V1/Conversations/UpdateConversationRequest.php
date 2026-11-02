<?php

namespace App\Http\Requests\Api\V1\Conversations;

use App\Models\AiModel;
use App\Models\Conversation;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/conversations/{conversation} — rename the conversation (US-2.6)
 * and/or switch the AI model it uses (US-3.5).
 */
class UpdateConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Conversation|null $conversation */
        $conversation = $this->route('conversation');

        return $conversation !== null && $this->user()->can('update', $conversation);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:120'],
            'ai_model_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    $available = AiModel::query()
                        ->whereKey($value)
                        ->active()
                        ->text()
                        ->whereHas('provider', fn (Builder $providers) => $providers->active())
                        ->exists();

                    if (! $available) {
                        $fail('The selected AI model is not available for this conversation.');
                    }
                },
            ],
        ];
    }
}
