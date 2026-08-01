<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

// The deliberate counterpart to Money — which refuses string parsing to stay
// integer-only: parsing lives here at the input boundary and hands the system
// a clean int. Accepts plain ("12.50"), Dutch-grouped ("1.234,56") and
// comma-decimal ("12,50") forms; the rightmost of '.' or ',' is the decimal.
final class MoneyInput
{
    // Signed because an amount can be negative — a debit, or a negative
    // statement balance — so a leading '-' is honoured. Returns null when the
    // string is empty or not a well-formed amount of at most two decimals.
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

    // The magnitude-only variant: like tryToMinor(), but rejects zero and
    // negatives. For inputs whose sign is fixed by context — a split leg, a
    // pot/goal/budget target — where "0,00" or a minus is not a valid entry.
    public static function tryToPositiveMinor(string $value): ?int
    {
        $minor = self::tryToMinor($value);

        return $minor !== null && $minor > 0 ? $minor : null;
    }

    // e.g. -5000 -> "-50,00": the plain, symbol-free Dutch-decimal form the
    // amount inputs round-trip through, so a value shown then submitted
    // untouched parses back to the same minor units via tryToMinor().
    public static function formatMinor(int $minor): string
    {
        return ($minor < 0 ? '-' : '').self::formatAbsMinor($minor);
    }

    // The magnitude only, e.g. 5000 (or -5000) -> "50,00", for inputs that
    // carry their sign separately from the number they display.
    public static function formatAbsMinor(int $minor): string
    {
        $abs = abs($minor);

        return intdiv($abs, Money::MINOR_UNITS_PER_MAJOR).','.str_pad((string) ($abs % Money::MINOR_UNITS_PER_MAJOR), 2, '0', STR_PAD_LEFT);
    }
}
