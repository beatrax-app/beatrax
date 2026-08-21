<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Database\QueryException;
use Throwable;

// What went wrong, without what it went wrong ON. A QueryException's message
// carries the statement AND its bindings, and here the bindings ARE the data:
// a relay pairing frame, a transaction's counterparty. Logging getMessage()
// writes what the encryption exists to keep off disk, into a 0644 daily log.
final class SafeExceptionContext
{
    /**
     * @return array{reason: string, sqlstate: string}
     */
    public static function describe(Throwable $e): array
    {
        // SQLSTATE distinguishes the failures worth acting on — a lock
        // timeout from a constraint violation — and carries no row data.
        $sqlstate = $e instanceof QueryException && is_string($e->getCode()) ? $e->getCode() : '';

        return ['reason' => $e::class, 'sqlstate' => $sqlstate];
    }
}
