<?php

namespace App\Exceptions;

use App\Models\Task;
use RuntimeException;

/**
 * Only failed tasks can be retried (US-10.3): any other state maps to 409.
 */
class TaskNotRetryableException extends RuntimeException
{
    public function __construct(Task $task)
    {
        parent::__construct("A tarefa [{$task->id}] não pode ser repetida no estado atual.");
    }
}
