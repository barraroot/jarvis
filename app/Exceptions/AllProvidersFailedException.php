<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when every model in the fallback chain failed (US-3.4).
 *
 * Carries the model identifiers that were attempted — never credentials.
 */
class AllProvidersFailedException extends RuntimeException
{
    /**
     * @param  list<string>  $attemptedModels
     */
    public function __construct(public readonly array $attemptedModels, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf(
            'All AI providers failed. Attempted models: %s',
            $attemptedModels === [] ? '(none)' : implode(', ', $attemptedModels),
        ), 0, $previous);
    }
}
