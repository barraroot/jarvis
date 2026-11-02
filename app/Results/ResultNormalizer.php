<?php

namespace App\Results;

use App\Exceptions\ResultValidationException;
use App\Models\ResultType;

/**
 * Safe entry point for persisting structured results: validates the raw
 * payload and returns a canonical `{type, payload}` pair. Anything invalid
 * falls back to a text payload flagged with `meta.result_invalid` so the UI
 * can warn instead of breaking (US-11.1).
 */
class ResultNormalizer
{
    public function __construct(private readonly ResultValidator $validator) {}

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{type: string, payload: array<string, mixed>}
     */
    public function normalize(?array $payload): array
    {
        if ($payload === null) {
            return ['type' => ResultType::TEXT, 'payload' => ['type' => ResultType::TEXT, 'text' => '']];
        }

        try {
            $dto = ResultPayload::fromArray($payload);

            return ['type' => $dto::type(), 'payload' => $dto->toArray()];
        } catch (ResultValidationException) {
            return ['type' => ResultType::TEXT, 'payload' => $this->fallbackToText($payload)];
        }
    }

    /**
     * Converts an invalid payload into a plain text payload flagged with
     * `meta.result_invalid`. When the raw payload carried a `text` field it is
     * preserved; otherwise the text is empty and the streamed message content
     * remains the readable answer.
     *
     * @param  array<string, mixed>  $invalid
     * @return array<string, mixed>
     */
    public function fallbackToText(array $invalid): array
    {
        $text = is_string($invalid['text'] ?? null) ? $invalid['text'] : '';

        return ['type' => ResultType::TEXT, 'text' => $text, 'meta' => ['result_invalid' => true]];
    }
}
