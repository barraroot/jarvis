<?php

namespace App\Http\Requests\Api\V1\Activity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/activity — period (`from`/`to`, inclusive dates), activity
 * `type` and page of the operator's timeline (US-12.4).
 */
class ListActivityRequest extends FormRequest
{
    /**
     * Activity kinds the timeline can be filtered by.
     *
     * @var list<string>
     */
    public const array TYPES = ['agent', 'tool', 'approval'];

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
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'type' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::TYPES)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
