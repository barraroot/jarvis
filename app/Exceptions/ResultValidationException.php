<?php

namespace App\Exceptions;

use App\Results\ResultNormalizer;
use RuntimeException;

/**
 * A structured result payload does not satisfy its JSON Schema contract
 * (`resources/schemas/results/*.json`). Callers that cannot recover surface
 * the message; {@see ResultNormalizer} catches it and falls back
 * to a text payload flagged with `meta.result_invalid`.
 */
class ResultValidationException extends RuntimeException
{
    //
}
