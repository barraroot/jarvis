<?php

namespace App\Results\Payloads;

use App\Models\ResultType;
use App\Results\ResultPayload;

/**
 * Titled list of labeled items with optional detail lines.
 */
class ListResult extends ResultPayload
{
    public static function type(): string
    {
        return ResultType::LIST;
    }

    /**
     * @param  list<array{label: string, detail?: string}>  $items
     * @param  list<array{label: string, request: string}>  $suggestedActions
     */
    public function __construct(
        ?string $speech,
        array $suggestedActions,
        public readonly ?string $title,
        public readonly array $items,
    ) {
        parent::__construct($speech, $suggestedActions);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['type' => self::type(), 'title' => $this->title, 'items' => $this->items, ...$this->commonFields()];
    }
}
