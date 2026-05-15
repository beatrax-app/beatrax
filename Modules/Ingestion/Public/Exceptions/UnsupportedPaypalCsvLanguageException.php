<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when the PayPal CSV header row does not match any registered
 * language profile. Message text is user-facing — the upload wizard
 * renders it verbatim so the user knows which locales are currently
 * supported.
 */
final class UnsupportedPaypalCsvLanguageException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
