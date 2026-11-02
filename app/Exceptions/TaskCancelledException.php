<?php

namespace App\Exceptions;

use App\Models\Task;
use RuntimeException;

/**
 * Raised inside `RunTask` when the owner cancelled the task (US-10.3): the
 * cooperative cancellation flag is checked before each step registration, so
 * a cancelled task never starts another step.
 */
class TaskCancelledException extends RuntimeException
{
    public function __construct(Task $task)
    {
        parent::__construct("A tarefa [{$task->id}] foi cancelada pelo usuário.");
    }
}
