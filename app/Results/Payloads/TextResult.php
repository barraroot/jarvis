<?php

namespace App\Results\Payloads;

use App\Models\ResultType;
use App\Results\ResultPayload;

/**
 * Plain conversational reply, rendered as sanitized Markdown.
 */
class TextResult extends ResultPayload
{
    public static function type(): string
    {
        return ResultType::TEXT;
    }

    /**
     * @param  list<array{label: string, request: string}>  $suggestedActions
     */
    public function __construct(
        ?string $speech,
        array $suggestedActions,
        public readonly string $text,
    ) {
        parent::__construct($speech, $suggestedActions);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['type' => self::type(), 'text' => $this->text, ...$this->commonFields()];
    }
}
