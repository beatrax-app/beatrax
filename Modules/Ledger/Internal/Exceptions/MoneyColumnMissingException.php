<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Exceptions;

use RuntimeException;

// A partial SELECT has to fail loudly rather than fabricate a (0, 'EUR') the
// caller cannot tell from a real zero.
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
