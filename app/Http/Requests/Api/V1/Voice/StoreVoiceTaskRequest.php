<?php

namespace App\Http\Requests\Api\V1\Voice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/voice/tasks — a background task requested by voice (CT-02).
 * Session ownership (404) and visualization ownership (403) are checked by
 * the controller.
 */
class StoreVoiceTaskRequest extends FormRequest
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
            'voice_session_id' => ['required', 'integer'],
            'request' => ['required', 'string', 'min:1', 'max:4000'],
            'visualization_id' => ['nullable', 'integer', 'exists:visualizations,id'],
        ];
    }
}
