<?php

namespace App\Tools\Exceptions;

use RuntimeException;

/**
 * The model sent input that violates the tool's `input_schema`. The handler
 * never runs; a failed `tool_execution` row carries the validation errors.
 */
class ToolInputInvalidException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly string $identifier,
        public readonly array $errors,
    ) {
        parent::__construct("Invalid input for tool [{$identifier}]: ".implode('; ', $errors));
    }
}
