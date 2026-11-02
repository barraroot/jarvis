<?php

namespace App\Results\Payloads;

use App\Models\ResultType;
use App\Results\ResultPayload;

/**
 * Column-oriented table (e.g. ERP search results mapped by the agent).
 */
class TableResult extends ResultPayload
{
    /**
     * @param  list<string>  $columns
     * @param  list<list<string|int|float|bool|null>>  $rows
     * @param  list<array{label: string, request: string}>  $suggestedActions
     */
    public function __construct(
        ?string $speech,
        array $suggestedActions,
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?string $caption,
    ) {
        parent::__construct($speech, $suggestedActions);
    }

    public static function type(): string
    {
        return ResultType::TABLE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->compact(['type' => self::type(), 'columns' => $this->columns, 'rows' => $this->rows, 'caption' => $this->caption, ...$this->commonFields()]);
    }
}
