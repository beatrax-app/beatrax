<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Carbon\CarbonImmutable;
use Throwable;

final class SafeDate
{
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

    // A date-only field parsed off a form: the time half is whatever the
    // parser inferred rather than anything the reader typed, so it is
    // flattened here instead of leaking into a range comparison.
    public static function parseDayOrNull(string $raw): ?CarbonImmutable
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
            return null;
        }

        if (! $parsed instanceof CarbonImmutable) {
            return null;
        }

        $errors = CarbonImmutable::getLastErrors();

        if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            return null;
        }

        return $parsed;
    }
}
