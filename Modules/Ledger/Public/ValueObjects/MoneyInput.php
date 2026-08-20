<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

// The string parsing Money refuses, confined to the input boundary. Accepts
// "12.50", "1.234,56" and "12,50"; the rightmost of '.' or ',' is the decimal.
final class MoneyInput
{
    // Null — never a guess — for anything that is not a well-formed amount of
    // at most two decimals. A leading '-' is honoured.
    public static function tryToMinor(string $value): ?int
    {
        $trimmed = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($trimmed === '') {
            return null;
        }

        $negative = str_starts_with($trimmed, '-');
        $unsigned = $negative ? substr($trimmed, 1) : $trimmed;

        $lastDot = strrpos($unsigned, '.');
        $lastComma = strrpos($unsigned, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $unsigned = $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $unsigned)
                : str_replace(',', '', $unsigned);
        } elseif ($lastComma !== false) {
            $unsigned = str_replace(',', '.', $unsigned);
        }

        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $unsigned) !== 1) {
            return null;
        }

        [$whole, $frac] = array_pad(explode('.', $unsigned, 2), 2, '');
        $minor = (int) $whole * Money::MINOR_UNITS_PER_MAJOR + (int) str_pad($frac, 2, '0');

        return $negative ? -$minor : $minor;
    }

    // Rejects zero as well as negatives, for inputs whose sign is fixed by
    // context — a split leg, a pot/goal/budget target.
    public static function tryToPositiveMinor(string $value): ?int
    {
        $minor = self::tryToMinor($value);

        return $minor !== null && $minor > 0 ? $minor : null;
    }

    // -5000 -> "-50,00": symbol-free, so a value shown then submitted
    // untouched parses back to the same minor units via tryToMinor().
    public static function formatMinor(int $minor): string
    {
        return ($minor < 0 ? '-' : '').self::formatAbsMinor($minor);
    }

    // 5000 or -5000 -> "50,00", for inputs that carry their sign separately.
    public static function formatAbsMinor(int $minor): string
    {
        $abs = abs($minor);

        return intdiv($abs, Money::MINOR_UNITS_PER_MAJOR).','.str_pad((string) ($abs % Money::MINOR_UNITS_PER_MAJOR), 2, '0', STR_PAD_LEFT);
    }
}
