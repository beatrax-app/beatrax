<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-date-from-outside-normalised-instead-of-refused
 */
final class SafeDate
{
    public const string DAY_FORMAT = 'Y-m-d';

    // CarbonImmutable::parse('') returns NOW instead of throwing, so a blank
    // field would sail past a bare try/catch and book itself today. The
    // emptiness check is the load-bearing half; the catch is the rest.
    public static function parseOrNull(string $raw): ?CarbonImmutable
    {
        if (trim($raw) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    // The one reading of "is this the day somebody meant". Every parser in PHP
    // rolls an out-of-range component FORWARD rather than refusing it, so the
    // result is formatted back and compared to what arrived: '2027-02-29',
    // '2026-1-5', '2026' and 'tomorrow' all fail that comparison.
    public static function dayOrNull(string $raw): ?CarbonImmutable
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!'.self::DAY_FORMAT, $trimmed);
        } catch (Throwable) {
            return null;
        }

        return $parsed instanceof CarbonImmutable && $parsed->format(self::DAY_FORMAT) === $trimmed
            ? $parsed
            : null;
    }

    // The same exact reading, for a value that reached a DATE column through
    // a cast that stamped a time on it. Only a recognisable time suffix is set
    // aside; the day itself still has to survive dayOrNull, which is what
    // keeps '2027-02-29 00:00:00' refused rather than rolled forward.
    public static function dayIgnoringTimeOrNull(string $raw): ?CarbonImmutable
    {
        $trimmed = trim($raw);
        $pattern = '/^(\\d{4}-\\d{2}-\\d{2})[ T]\\d{2}:\\d{2}(?::\\d{2})?(?:\\.\\d+)?(?:Z|[+-]\\d{2}:?\\d{2})?$/';

        return self::dayOrNull(preg_match($pattern, $trimmed, $match) === 1 ? $match[1] : $trimmed);
    }

    // Named for what it does, because it is the wrong answer for anything a
    // reader or a peer supplies: a machine-emitted free-form string — a MIME
    // `Date:` header, a stored timestamp whose time half is an artefact — has
    // no Y-m-d shape to check, so this parses what it can and rolls the rest.
    public static function normalisedDayOrNull(string $raw): ?CarbonImmutable
    {
        return self::parseOrNull(trim($raw))?->startOfDay();
    }

    // createFromFormat() rolls an out-of-range component forward rather than
    // refusing it: "31-02-2026" books itself on 3 March. The roll shows up
    // only as a parse warning, which is what gets checked — a format
    // round-trip would also reject "02/05/2026" read through "n/j/Y".
    public static function fromFormatOrNull(string $format, string $raw): ?CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat($format, $raw);
        } catch (Throwable) {
            $parsed = false;
        }

        if (! $parsed instanceof CarbonImmutable) {
            return null;
        }

        $errors = CarbonImmutable::getLastErrors();
        $rejected = ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0;

        return $rejected ? null : $parsed;
    }
}
