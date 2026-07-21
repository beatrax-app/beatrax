<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;
use Throwable;

// Thrown when the upload matches a recognised PayPal export shape this app
// does not consume (e.g. the Balance Reconciliation Report rather than the
// per-event Activity Download) — without this check the sniffer would fall
// through to the language-profile check and surface a misleading error.
final class UnsupportedPaypalCsvShapeException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
