<?php

namespace App\Http\Requests\Api\V1\Approvals;

use Illuminate\Foundation\Http\FormRequest;

class DenyApprovalRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
