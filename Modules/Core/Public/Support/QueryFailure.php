<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Database\QueryException;

// Classify the failure, never the wording: 23000 is the SQLSTATE SQLite,
// MySQL and Postgres all set for a unique violation, so a driver that sets
// it is answered without reading the message at all.
final class QueryFailure
{
    // The fallback for a driver that leaves the SQLSTATE unset, unioned from
    // every phrasing the call sites were separately probing for.
    private const MESSAGE_PROBES = [
        'UNIQUE constraint failed',
        'Duplicate entry',
        'duplicate key value',
        'Integrity constraint violation',
    ];

    public static function isUniqueViolation(QueryException $e): bool
    {
        if ((string) $e->getCode() === '23000') {
            return true;
        }

        $message = $e->getMessage();
        foreach (self::MESSAGE_PROBES as $probe) {
            if (str_contains($message, $probe)) {
                return true;
            }
        }

        return false;
    }
}
