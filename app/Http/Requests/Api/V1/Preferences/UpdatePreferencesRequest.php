<?php

namespace App\Http\Requests\Api\V1\Preferences;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PUT /api/v1/preferences — locale and voice settings (US-4.5) plus the wake
 * word opt-in (US-4.4). `wake_word_consent` is a gate on the payload, not a
 * stored column: accepting it records the current consent text version (RF-22).
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
     * Enabling the wake word requires a valid consent for the current text
     * (RF-22): either already stored or accepted in the same payload. Consents
     * given for an older text no longer count.
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

                if (! $this->boolean('wake_word_enabled') || $this->boolean('wake_word_consent')) {
                    return;
                }

                if ($this->user()->preference()->first()?->hasValidWakeWordConsent()) {
                    return;
                }

                $validator->errors()->add('wake_word_consent', __('voice.errors.wake_word_consent_required'));
            },
        ];
    }
}
