<?php

namespace App\Results\Payloads;

use App\Models\ResultType;
use App\Results\ResultPayload;

/**
 * Highlighted metric with an optional mini time series.
 */
class DataResult extends ResultPayload
{
    /**
     * @param  array{label?: string, values: list<int|float>}|null  $series
     * @param  list<array{label: string, request: string}>  $suggestedActions
     */
    public function __construct(
        ?string $speech,
        array $suggestedActions,
        public readonly string $label,
        public readonly string|int|float $value,
        public readonly ?string $unit,
        public readonly ?array $series,
    ) {
        parent::__construct($speech, $suggestedActions);
    }

    public static function type(): string
    {
        return ResultType::DATA;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->compact(['type' => self::type(), 'label' => $this->label, 'value' => $this->value, 'unit' => $this->unit, 'series' => $this->series, ...$this->commonFields()]);
    }
}
