<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;
use Throwable;

// Thrown when the PayPal CSV header row matches no registered language
// profile; message text is user-facing.
final class UnsupportedPaypalCsvLanguageException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
