<?php

namespace App\Http\Requests\Api\V1\Conversations;

use App\Models\AiModel;
use App\Models\Conversation;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/conversations/{conversation} — switch the conversation's AI model.
 */
class UpdateConversationModelRequest extends FormRequest
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
