<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * The connector URL is not an allowed HTTP(S) endpoint (US-9.1/9.2): stdio and
 * other schemes are rejected, as are private/loopback hosts outside local dev.
 */
class InvalidMcpUrlException extends InvalidArgumentException {}
