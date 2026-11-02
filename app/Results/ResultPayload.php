<?php

namespace App\Results;

use App\Exceptions\ResultValidationException;
use App\Models\ResultType;
use App\Results\Payloads\DataResult;
use App\Results\Payloads\DocumentResult;
use App\Results\Payloads\ExecutionStatusResult;
use App\Results\Payloads\ListResult;
use App\Results\Payloads\ReportResult;
use App\Results\Payloads\SearchResultsResult;
use App\Results\Payloads\TableResult;
use App\Results\Payloads\TaskCardResult;
use App\Results\Payloads\TextResult;

/**
 * Typed, validated structured result payload (US-11.1). Every payload carries
 * an optional `speech` summary (≤ 300 chars, for TTS) and up to four
 * `suggested_actions` ({label ≤ 40, request ≤ 500}); the concrete subclass
 * holds the type-specific fields.
 */
abstract class ResultPayload
{
    /**
     * @param  list<array{label: string, request: string}>  $suggestedActions
     */
    public function __construct(
        public readonly ?string $speech,
        public readonly array $suggestedActions,
    ) {}

    abstract public static function type(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * Validates the raw payload against the JSON Schema of its declared type
     * and returns the matching DTO.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ResultValidationException
     */
    public static function fromArray(array $data): static
    {
        $type = $data['type'] ?? null;

        if (! is_string($type)) {
            throw new ResultValidationException('Payload must declare a string "type".');
        }

        $class = self::classFor($type);

        if ($class === null) {
            throw new ResultValidationException("Unknown result type [{$type}].");
        }

        app(ResultValidator::class)->validate($type, $data);

        $speech = is_string($data['speech'] ?? null) ? $data['speech'] : null;
        $suggestedActions = $data['suggested_actions'] ?? [];

        return match ($type) {
            ResultType::TEXT => new TextResult($speech, $suggestedActions, $data['text']),
            ResultType::LIST => new ListResult($speech, $suggestedActions, $data['title'] ?? null, $data['items']),
            ResultType::TABLE => new TableResult($speech, $suggestedActions, $data['columns'], $data['rows'], $data['caption'] ?? null),
            ResultType::REPORT => new ReportResult($speech, $suggestedActions, $data['title'], $data['sections']),
            ResultType::SEARCH_RESULTS => new SearchResultsResult($speech, $suggestedActions, $data['query'], $data['results']),
            ResultType::TASK_CARD => new TaskCardResult($speech, $suggestedActions, $data['task_id'], $data['title'], $data['status'], $data['progress']),
            ResultType::DATA => new DataResult($speech, $suggestedActions, $data['label'], $data['value'], $data['unit'] ?? null, $data['series'] ?? null),
            ResultType::EXECUTION_STATUS => new ExecutionStatusResult($speech, $suggestedActions, $data['entity'], $data['name'] ?? null, $data['status'], $data['message']),
            ResultType::DOCUMENT => new DocumentResult($speech, $suggestedActions, $data['title'], $data['format'], $data['body']),
        };
    }

    /**
     * Common envelope fields, present only when meaningful.
     *
     * @return array<string, mixed>
     */
    protected function commonFields(): array
    {
        return array_filter([
            'speech' => $this->speech,
            'suggested_actions' => $this->suggestedActions,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Drops null optionals so the canonical payload always validates against
     * its own schema on round trips.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function compact(array $payload): array
    {
        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return class-string<static>|null
     */
    private static function classFor(string $type): ?string
    {
        return match ($type) {
            ResultType::TEXT => TextResult::class,
            ResultType::LIST => ListResult::class,
            ResultType::TABLE => TableResult::class,
            ResultType::REPORT => ReportResult::class,
            ResultType::SEARCH_RESULTS => SearchResultsResult::class,
            ResultType::TASK_CARD => TaskCardResult::class,
            ResultType::DATA => DataResult::class,
            ResultType::EXECUTION_STATUS => ExecutionStatusResult::class,
            ResultType::DOCUMENT => DocumentResult::class,
            default => null,
        };
    }
}
