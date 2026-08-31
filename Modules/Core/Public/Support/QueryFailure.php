<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Database\QueryException;

// Classify the failure, never the wording -- except that on this stack the
// wording is the only thing that classifies it. SQLite answers UNIQUE, FOREIGN
// KEY, NOT NULL and CHECK alike with SQLSTATE 23000 and driver code 19, so a
// caller reading the SQLSTATE was told "already exists" about a failed write.
final class QueryFailure
{
    // The one driver whose SQLSTATE is already precise: 23000 is its GENERIC
    // integrity violation and 23505 is specifically unique.
    private const string POSTGRES_UNIQUE_SQLSTATE = '23505';

    // SQLite (primary-key collisions included), MySQL/MariaDB 1062, and the
    // PostgreSQL phrasing for a driver that did not carry 23505 through.
    /**
     * @var list<string>
     */
    private const array UNIQUE_PHRASES = [
        'UNIQUE constraint failed',
        'Duplicate entry',
        'duplicate key value',
    ];

    public static function isUniqueViolation(QueryException $e): bool
    {
        if ((string) $e->getCode() === self::POSTGRES_UNIQUE_SQLSTATE) {
            return true;
        }

        $message = self::driverMessage($e);

        return array_any(self::UNIQUE_PHRASES, fn (string $phrase): bool => str_contains($message, $phrase));
    }

    // The driver's own sentence, not the exception message: Laravel appends the
    // statement AND its bindings to the latter, so a stored value that happened
    // to contain one of the phrases above would classify its own write.
    private static function driverMessage(QueryException $e): string
    {
        $info = $e->errorInfo;
        $driverMessage = is_array($info) ? ($info[2] ?? null) : null;

        return is_string($driverMessage) ? $driverMessage : $e->getMessage();
    }
}
