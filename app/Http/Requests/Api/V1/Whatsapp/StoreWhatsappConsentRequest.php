<?php

namespace App\Http\Requests\Api\V1\Whatsapp;

use App\Models\WhatsappConsent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/whatsapp/consent (CT-01): the accepted text version and 1 to
 * 4 distinct scopes. A version other than the current one is rejected by
 * `WhatsappConsentService::grant` (422 on `version`).
 */
class StoreWhatsappConsentRequest extends FormRequest
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
            'version' => ['required', 'integer', 'min:1'],
            'scopes' => ['required', 'array', 'min:1', 'max:'.count(WhatsappConsent::SCOPES)],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(WhatsappConsent::SCOPES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scopes.*' => __('whatsapp.consent.invalid_scopes'),
            'scopes.required' => __('whatsapp.consent.invalid_scopes'),
            'scopes.array' => __('whatsapp.consent.invalid_scopes'),
            'scopes.min' => __('whatsapp.consent.invalid_scopes'),
            'scopes.max' => __('whatsapp.consent.invalid_scopes'),
        ];
    }
}
