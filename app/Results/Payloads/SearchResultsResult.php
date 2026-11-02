<?php

namespace App\Results\Payloads;

use App\Models\ResultType;
use App\Results\ResultPayload;

/**
 * Search hit cards with source attribution.
 */
class SearchResultsResult extends ResultPayload
{
    /**
     * @param  list<array{title: string, url?: string, snippet: string, source: string}>  $results
     * @param  list<array{label: string, request: string}>  $suggestedActions
     */
    public function __construct(
        ?string $speech,
        array $suggestedActions,
        public readonly string $query,
        public readonly array $results,
    ) {
        parent::__construct($speech, $suggestedActions);
    }

    public static function type(): string
    {
        return ResultType::SEARCH_RESULTS;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['type' => self::type(), 'query' => $this->query, 'results' => $this->results, ...$this->commonFields()];
    }
}
