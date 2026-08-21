<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;
use Throwable;

// Not final: MissingPaypalTransactionTypeMapException extends it as the narrower
// code-internal-inconsistency variant.
class UnknownPaypalEventTypeException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
