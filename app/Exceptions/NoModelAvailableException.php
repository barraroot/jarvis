<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when no active AI model can serve a request (US-5.2).
 */
class NoModelAvailableException extends RuntimeException
{
    //
}
