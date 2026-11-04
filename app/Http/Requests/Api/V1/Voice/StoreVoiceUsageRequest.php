<?php

namespace App\Http\Requests\Api\V1\Voice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/voice/sessions/{voice_session}/usage — the `usage` of one
 * Realtime `response.done` event (CT-07). Ownership is checked by the
 * controller with the policy.
 */
class StoreVoiceUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tokens = ['integer', 'min:0'];

        return [
            'response_id' => ['required', 'string', 'min:1', 'max:64'],
            'usage' => ['required', 'array'],
            'usage.input_tokens' => ['required', ...$tokens],
            'usage.output_tokens' => ['required', ...$tokens],
            'usage.input_token_details' => ['sometimes', 'array'],
            'usage.input_token_details.cached_tokens' => ['sometimes', ...$tokens],
            'usage.input_token_details.text_tokens' => ['sometimes', ...$tokens],
            'usage.input_token_details.audio_tokens' => ['sometimes', ...$tokens],
            'usage.output_token_details' => ['sometimes', 'array'],
            'usage.output_token_details.text_tokens' => ['sometimes', ...$tokens],
            'usage.output_token_details.audio_tokens' => ['sometimes', ...$tokens],
        ];
    }
}
