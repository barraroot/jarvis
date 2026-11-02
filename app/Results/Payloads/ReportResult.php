<?php

namespace App\Results\Payloads;

use App\Models\ResultType;
use App\Results\ResultPayload;

/**
 * Multi-section report; each section body is Markdown, never interpreted HTML.
 */
class ReportResult extends ResultPayload
{
    /**
     * @param  list<array{heading: string, body: string}>  $sections
     * @param  list<array{label: string, request: string}>  $suggestedActions
     */
    public function __construct(
        ?string $speech,
        array $suggestedActions,
        public readonly string $title,
        public readonly array $sections,
    ) {
        parent::__construct($speech, $suggestedActions);
    }

    public static function type(): string
    {
        return ResultType::REPORT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['type' => self::type(), 'title' => $this->title, 'sections' => $this->sections, ...$this->commonFields()];
    }
}
