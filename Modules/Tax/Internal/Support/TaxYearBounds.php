<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Support;

// The window a tax year may name at all. The tag write contract bounds
// tax_year_override to it, and /tax's ?year= is the same value arriving by a
// different door — a second bound there would render a year no tag can be
// filed under.
final class TaxYearBounds
{
    public const int SPAN_YEARS = 10;

    public static function contains(int $year, int $currentYear): bool
    {
        return $year >= $currentYear - self::SPAN_YEARS
            && $year <= $currentYear + self::SPAN_YEARS;
    }

    public static function clamp(int $year, int $currentYear): int
    {
        return max($currentYear - self::SPAN_YEARS, min($currentYear + self::SPAN_YEARS, $year));
    }
}
