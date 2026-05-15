<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when a PayPal CSV row carries an event-type string that is
 * not present in `PaypalCsvEventTypeMap` for the detected language.
 * Surfacing as a typed exception keeps the adapter from silently
 * mis-classifying unfamiliar event types — the user is asked to
 * report the row so a new mapping can be added explicitly.
 */
final class UnknownPaypalEventTypeException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
