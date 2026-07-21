<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;
use Throwable;

// Thrown when a PayPal CSV row carries an event-type string absent from
// PaypalCsvEventTypeMap for the detected language, rather than silently
// mis-classifying it. Not final: MissingPaypalTransactionTypeMapException
// extends it as a narrower code-internal-inconsistency variant.
class UnknownPaypalEventTypeException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
