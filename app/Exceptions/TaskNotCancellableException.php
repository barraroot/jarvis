<?php

namespace App\Exceptions;

use App\Models\Task;
use RuntimeException;

/**
 * The task is not in a cancellable state (US-10.3): the API maps it to 409.
 */
class TaskNotCancellableException extends RuntimeException
{
    public function __construct(Task $task)
    {
        parent::__construct("A tarefa [{$task->id}] não pode ser cancelada no estado atual.");
    }
}
