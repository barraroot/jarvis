<?php

namespace App\Http\Requests\Api\V1\Voice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/voice/sessions — a new wake session or the renewal of an
 * existing one (CT-01). Ownership of `voice_session_id` is checked by the
 * controller with the policy (403).
 */
class StoreVoiceSessionRequest extends FormRequest
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
        return [
            'locale' => ['sometimes', 'string', 'regex:/^[a-z]{2}-[A-Z]{2}$/'],
            'voice_session_id' => ['sometimes', 'integer', 'min:1', 'exists:voice_sessions,id'],
        ];
    }
}
