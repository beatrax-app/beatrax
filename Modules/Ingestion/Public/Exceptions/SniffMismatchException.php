<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by HeaderSniffer when an uploaded file does not match the declared
 * source format. Message text is user-facing — the upload wizard renders it
 * verbatim per UI-SPEC §Error states.
 */
final class SniffMismatchException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
