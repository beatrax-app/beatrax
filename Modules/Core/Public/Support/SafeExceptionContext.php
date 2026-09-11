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

    // describe() stays a strip: it is handed every exception a broad catch can
    // receive, and cannot know which of them quotes a row. This one is handed
    // the same throwable and answers nothing unless the class itself promised a
    // cell, which is a claim its own throws are read against.
    /**
     * @return array{refused_file: string, refused_column: string, refused_value: string, refused_value_bytes: int}|array{}
     */
    public static function refusedCell(Throwable $e): array
    {
        $cell = $e instanceof NamesTheCellItRefused ? $e->refusedCell() : null;

        return $cell?->toLogContext() ?? [];
    }

    // For a column or a screen, where describe()'s two keys have nowhere to go.
    // Same promise as the narrowed read a broad catch is allowed: the message
    // survives only from a class that claimed it names no value out of a row.
    // mb_strcut and not substr, so the cap cannot split a character in half.
    public static function reason(Throwable $e, int $maxBytes = 500): string
    {
        $message = $e instanceof MessageNamesNoUserData ? $e->getMessage() : '';
        $oneLine = trim(PatternScan::replace('/\s+/', ' ', $message));

        return $oneLine === '' ? self::shortName($e) : mb_strcut($oneLine, 0, $maxBytes);
    }

    // The unqualified name, for a line a reader sees rather than a log. Same
    // guarantee as describe(): it names the failure, never the row.
    public static function shortName(Throwable $e): string
    {
        $fqcn = $e::class;
        $lastSeparator = strrpos($fqcn, '\\');

        return $lastSeparator === false ? $fqcn : substr($fqcn, $lastSeparator + 1);
    }
}
