<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a memory containing sensitive data would be persisted without
 * the user's explicit request (US-6.2).
 */
class SensitiveMemoryRejectedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sensitive data can only be stored as a memory when explicitly requested by the user.');
    }
}
