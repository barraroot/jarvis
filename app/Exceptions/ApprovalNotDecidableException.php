<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An approval cannot be decided: it belongs to someone else, was already
 * decided, or its window has closed. `reason` tells callers apart so the API
 * can map each case to its HTTP status.
 */
class ApprovalNotDecidableException extends RuntimeException
{
    public const string NOT_OWNER = 'not_owner';

    public const string NOT_PENDING = 'not_pending';

    public const string EXPIRED = 'expired';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
