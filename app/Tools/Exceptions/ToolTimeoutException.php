<?php

namespace App\Tools\Exceptions;

use RuntimeException;

/**
 * The handler exceeded the tool's `timeout_seconds` budget (raised from the
 * pcntl alarm handler, or after `set_time_limit` fired).
 */
class ToolTimeoutException extends RuntimeException {}
