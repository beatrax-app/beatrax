<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Exceptions;

use Modules\Core\Public\Support\MessageNamesNoUserData;
use RuntimeException;
use Throwable;

// Message text is user-facing.
final class UnsupportedPaypalCsvLanguageException extends RuntimeException implements MessageNamesNoUserData
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
