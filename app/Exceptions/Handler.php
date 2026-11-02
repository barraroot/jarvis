<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

/**
 * Redacts sensitive values (AI provider credentials) from exception context before
 * it reaches logs, reporters or serialized errors (US-5.1, project-description §4.1).
 */
class Handler extends ExceptionHandler
{
    /**
     * Get the default exception context variables for logging.
     *
     * @return array<array-key, mixed>
     */
    protected function exceptionContext(Throwable $e): array
    {
        return $this->redactApiKeys(parent::exceptionContext($e));
    }

    /**
     * Remove every `api_key` entry from the given context, including nested arrays.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    private function redactApiKeys(array $context): array
    {
        $redacted = [];

        foreach ($context as $key => $value) {
            if ($key === 'api_key') {
                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redactApiKeys($value) : $value;
        }

        return $redacted;
    }
}
