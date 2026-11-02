<?php

namespace App\Results\Payloads;

use App\Models\ResultType;
use App\Results\ResultPayload;

/**
 * Outcome of a tool or agent execution, shown as a status card.
 */
class ExecutionStatusResult extends ResultPayload
{
    /**
     * @param  'tool'|'agent'  $entity
     * @param  list<array{label: string, request: string}>  $suggestedActions
     */
    public function __construct(
        ?string $speech,
        array $suggestedActions,
        public readonly string $entity,
        public readonly ?string $name,
        public readonly string $status,
        public readonly string $message,
    ) {
        parent::__construct($speech, $suggestedActions);
    }

    public static function type(): string
    {
        return ResultType::EXECUTION_STATUS;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => self::type(),
            'entity' => $this->entity,
            'name' => $this->name,
            'status' => $this->status,
            'message' => $this->message,
            ...$this->commonFields(),
        ];
    }
}
