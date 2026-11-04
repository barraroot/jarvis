<?php

namespace App\Http\Requests\Api\V1\Voice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/voice/sessions/{voice_session}/turns — a batch of 1–20
 * transcribed voice turns (CT-10, D2). Ownership is checked by the controller
 * with the policy.
 */
class StoreVoiceTurnsRequest extends FormRequest
{
    public const int MAX_TURNS = 20;

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
            'turns' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_TURNS],
            'turns.*' => ['required', 'array'],
            'turns.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'turns.*.content' => ['required', 'string', 'min:1', 'max:4000'],
            'turns.*.spoken_at' => ['required', 'date'],
        ];
    }
}
