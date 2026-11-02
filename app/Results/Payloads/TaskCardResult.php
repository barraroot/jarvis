<?php

namespace App\Results\Payloads;

use App\Models\ResultType;
use App\Results\ResultPayload;

/**
 * Pointer to a background task, linking the chat to the task detail panel.
 */
class TaskCardResult extends ResultPayload
{
    /**
     * @param  list<array{label: string, request: string}>  $suggestedActions
     */
    public function __construct(
        ?string $speech,
        array $suggestedActions,
        public readonly int $taskId,
        public readonly string $title,
        public readonly string $status,
        public readonly int $progress,
    ) {
        parent::__construct($speech, $suggestedActions);
    }

    public static function type(): string
    {
        return ResultType::TASK_CARD;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => self::type(),
            'task_id' => $this->taskId,
            'title' => $this->title,
            'status' => $this->status,
            'progress' => $this->progress,
            ...$this->commonFields(),
        ];
    }
}
