<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Exceptions;

use Modules\Core\Public\Support\MessageNamesNoUserData;
use RuntimeException;
use Throwable;

final class SniffMismatchException extends RuntimeException implements MessageNamesNoUserData
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
