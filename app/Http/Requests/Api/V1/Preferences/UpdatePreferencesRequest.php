<?php

namespace App\Http\Requests\Api\V1\Preferences;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PUT /api/v1/preferences — locale and voice settings (US-4.5) plus the wake
 * word opt-in (US-4.4). `wake_word_consent` is a gate on the payload, not a
 * stored column.
 */
class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $preferences = $this->user()->preference()->firstOrCreate();

        return $this->user()->can('update', $preferences);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'locale' => ['sometimes', 'required', 'string', 'regex:/^[a-z]{2}-[A-Z]{2}$/'],
            'speech_locale' => ['sometimes', 'required', 'string', 'regex:/^[a-z]{2}-[A-Z]{2}$/'],
            'voice_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'speech_rate' => ['sometimes', 'required', 'numeric', 'between:0.5,2.0'],
            'spoken_responses_enabled' => ['sometimes', 'required', 'boolean'],
            'wake_word_enabled' => ['sometimes', 'required', 'boolean'],
            'wake_word_consent' => ['sometimes', 'required', 'boolean'],
            'silence_timeout_ms' => ['sometimes', 'required', 'integer', 'between:500,5000'],
            'extra' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * Enabling the wake word requires the privacy consent in the same payload
     * (US-4.4): continuous recognition may send audio to the browser vendor.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->boolean('wake_word_enabled') && ! $this->boolean('wake_word_consent')) {
                    $validator->errors()->add(
                        'wake_word_consent',
                        'Enabling the wake word requires accepting the browser speech recognition privacy notice.'
                    );
                }
            },
        ];
    }
}
