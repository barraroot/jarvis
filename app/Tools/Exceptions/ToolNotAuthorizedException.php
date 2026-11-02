<?php

namespace App\Tools\Exceptions;

use RuntimeException;

/**
 * The tool is inactive, not authorized for the requesting agent, or its
 * approval is no longer decidable. Nothing runs.
 */
class ToolNotAuthorizedException extends RuntimeException {}
