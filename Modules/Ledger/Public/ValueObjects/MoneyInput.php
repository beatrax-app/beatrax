<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

// The string parsing Money refuses, confined to the input boundary. Accepts
// "12.50", "1.234,56" and "12,50"; the rightmost of '.' or ',' is the decimal.
final class MoneyInput
{
    // A hand-typed amount is a household figure, and nine whole digits is
    // already past every one of them — a tenth is a slipped finger far more
    // often than a payment.
    public const int MAX_MINOR = 99_999_999_999;

    // Null — never a guess — for anything that is not a well-formed amount of
    // at most two decimals, or whose magnitude is past MAX_MINOR. A leading
    // '-' is honoured.
    public static function tryToMinor(string $value): ?int
    {
        $minor = self::parseAnyMagnitude($value);

        return $minor !== null && abs($minor) <= self::MAX_MINOR ? $minor : null;
    }

    // True when the input is a well-formed amount and only its size is the
    // problem, so a caller can say that rather than blame the digits.
    public static function exceedsMax(string $value): bool
    {
        $minor = self::parseAnyMagnitude($value);

        return $minor !== null && abs($minor) > self::MAX_MINOR;
    }

    // Shape only. The 15-digit ceiling keeps the minor-unit multiplication
    // inside a 64-bit int; past that there is nothing to weigh up.
    private static function parseAnyMagnitude(string $value): ?int
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

        if (preg_match('/^\d{1,15}(\.\d{1,2})?$/', $unsigned) !== 1) {
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

    // -123456 -> "-1.234,56": symbol-free, so a value shown then submitted
    // untouched parses back to the same minor units via tryToMinor().
    public static function formatMinor(int $minor): string
    {
        return ($minor < 0 ? '-' : '').self::formatAbsMinor($minor);
    }

    // 123456 or -123456 -> "1.234,56", for inputs that carry their sign
    // separately. Grouped because a five-figure balance in an input is read
    // before it is edited, and tryToMinor() takes the group mark back out.
    public static function formatAbsMinor(int $minor): string
    {
        $abs = abs($minor);
        $whole = (string) intdiv($abs, Money::MINOR_UNITS_PER_MAJOR);
        $grouped = strrev(implode('.', str_split(strrev($whole), 3)));

        return $grouped.','.str_pad((string) ($abs % Money::MINOR_UNITS_PER_MAJOR), 2, '0', STR_PAD_LEFT);
    }

    // The machine-readable form: "1234.56", no symbol and no group mark, for a
    // CSV cell or an API field where a reader's separators would be a bug.
    public static function toDecimalString(int $minor): string
    {
        $abs = abs($minor);

        return ($minor < 0 ? '-' : '').
            intdiv($abs, Money::MINOR_UNITS_PER_MAJOR).'.'.
            str_pad((string) ($abs % Money::MINOR_UNITS_PER_MAJOR), 2, '0', STR_PAD_LEFT);
    }
}
