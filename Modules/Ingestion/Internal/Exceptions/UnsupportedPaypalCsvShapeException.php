<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Exceptions;

use Modules\Core\Public\Support\MessageNamesNoUserData;
use RuntimeException;
use Throwable;

// A recognised PayPal export shape this app does not consume (the Balance Reconciliation
// Report, not the per-event Activity Download). Without it the sniffer would fall through
// to the language-profile check and surface a misleading error.
final class UnsupportedPaypalCsvShapeException extends RuntimeException implements MessageNamesNoUserData
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
