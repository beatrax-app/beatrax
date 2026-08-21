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
}
