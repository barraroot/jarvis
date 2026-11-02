<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Credentials used against the `web` guard; e-mail is normalised to lowercase.
     *
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'password' => (string) $this->input('password'),
        ];
    }
}
