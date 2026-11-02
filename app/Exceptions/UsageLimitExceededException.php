<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a user's monthly AI cost limit is exceeded (US-5.3).
 */
class UsageLimitExceededException extends RuntimeException
{
    //
}
