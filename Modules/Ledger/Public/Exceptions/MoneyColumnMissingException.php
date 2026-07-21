<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Exceptions;

use RuntimeException;

// Surfaces partial-SELECT misuse loudly rather than fabricating a
// silent (0, 'EUR') sentinel the caller cannot distinguish from a
// legitimate zero amount.
final class MoneyColumnMissingException extends RuntimeException
{
    public function __construct(string $minorColumn, string $currencyColumn)
    {
        parent::__construct(sprintf(
            'MoneyMinorCast cannot hydrate: the source row is missing one or both of "%s" and "%s". A SELECT that omits paired money columns must not be cast to Money — adjust the query or the cast configuration.',
            $minorColumn,
            $currencyColumn,
        ));
    }
}
