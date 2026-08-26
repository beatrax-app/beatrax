<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Exceptions;

use Modules\Core\Public\Support\MessageNamesNoUserData;
use Modules\Ingestion\Public\Contracts\NamesAFormatMismatch;
use RuntimeException;
use Throwable;

final class UnsupportedPaypalCsvLanguageException extends RuntimeException implements MessageNamesNoUserData, NamesAFormatMismatch
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
