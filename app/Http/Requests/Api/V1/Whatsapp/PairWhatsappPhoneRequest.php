<?php

namespace App\Http\Requests\Api\V1\Whatsapp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/whatsapp/session/pair-phone (CT-06): the phone number in
 * international format, digits only. Never persisted nor logged (RNF-10).
 */
class PairWhatsappPhoneRequest extends FormRequest
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
            'phone' => ['required', 'string', 'regex:/^[0-9]{10,15}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('whatsapp.session.phone_invalid'),
        ];
    }
}
