<?php

namespace App\Tools\Exceptions;

use RuntimeException;

/**
 * The handler returned a payload that violates the tool's `output_schema`.
 * The execution is recorded as `failed` with the schema errors.
 */
class ToolOutputSchemaException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct('Tool output violates the output schema: '.implode('; ', $errors));
    }
}
