<?php

namespace App\Results\Payloads;

use App\Models\ResultType;
use App\Results\ResultPayload;

/**
 * Generated document: Markdown body rendered sanitized, or plain text.
 */
class DocumentResult extends ResultPayload
{
    /**
     * @param  'markdown'|'plain'  $format
     * @param  list<array{label: string, request: string}>  $suggestedActions
     */
    public function __construct(
        ?string $speech,
        array $suggestedActions,
        public readonly string $title,
        public readonly string $format,
        public readonly string $body,
    ) {
        parent::__construct($speech, $suggestedActions);
    }

    public static function type(): string
    {
        return ResultType::DOCUMENT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['type' => self::type(), 'title' => $this->title, 'format' => $this->format, 'body' => $this->body, ...$this->commonFields()];
    }
}
