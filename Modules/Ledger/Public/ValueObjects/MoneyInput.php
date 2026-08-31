<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

use Modules\Ledger\Internal\Support\MoneyText;

// The string parsing Money refuses, confined to the input boundary. Accepts
// "12.50", "1.234,56" and "12,50"; the rightmost of '.' or ',' is the decimal.
final class MoneyInput
{
    // A hand-typed amount is a household figure, and nine whole digits is
    // already past every one of them — a tenth is a slipped finger far more
    // often than a payment.
    public const int MAX_MINOR = 99_999_999_999;

    // The 15-digit ceiling keeps the minor-unit multiplication inside a 64-bit
    // int; past that there is nothing left to weigh up, so the shape check
    // refuses the figure rather than the cast quietly losing it.
    public const int MAX_WHOLE_DIGITS = 15;

    // Every group mark a shipped locale uses, so a figure this class wrote
    // parses back through tryToMinor(): a plain space, the non-breaking one
    // twelve locales group with, and French's narrow no-break space.
    private const array GROUP_MARKS = [' ', "\u{00A0}", "\u{202F}"];

    // Null — never a guess — for anything that is not a well-formed amount at
    // the currency's own scale, or whose magnitude is past MAX_MINOR. A
    // leading '-' is honoured. Without a currency the two-decimal assumption
    // holds, which is what every caller with nothing else to say wants.
    public static function tryToMinor(string $value, ?string $currencyCode = null): ?int
    {
        $minor = self::parseAnyMagnitude($value, $currencyCode);

        return $minor !== null && abs($minor) <= self::MAX_MINOR ? $minor : null;
    }

    // True when the input is a well-formed amount and only its size is the
    // problem, so a caller can say that rather than blame the digits.
    public static function exceedsMax(string $value, ?string $currencyCode = null): bool
    {
        $minor = self::parseAnyMagnitude($value, $currencyCode);

        return $minor !== null && abs($minor) > self::MAX_MINOR;
    }

    // Shape only, with no MAX_MINOR ceiling applied, so exceedsMax() can tell
    // a figure that is merely too large from one that is not an amount at all.
    private static function parseAnyMagnitude(string $value, ?string $currencyCode = null): ?int
    {
        $scale = CurrencyScale::minorUnitsPerMajor($currencyCode);
        $decimals = CurrencyScale::decimalsOfScale($scale);

        $trimmed = str_replace(self::GROUP_MARKS, '', trim($value));
        if ($trimmed === '') {
            return null;
        }

        $negative = str_starts_with($trimmed, '-');
        $unsigned = self::pointedDecimal($negative ? substr($trimmed, 1) : $trimmed, $decimals);

        $fraction = $decimals === 0 ? '' : '(\.\d{1,'.$decimals.'})?';
        if (preg_match('/^\d{1,'.self::MAX_WHOLE_DIGITS.'}'.$fraction.'$/', $unsigned) !== 1) {
            return null;
        }

        [$whole, $frac] = array_pad(explode('.', $unsigned, 2), 2, '');
        $minor = (int) $whole * $scale + (int) str_pad($frac, $decimals, '0');

        return $negative ? -$minor : $minor;
    }

    // Whichever of '.' and ',' the writer meant as the decimal, rewritten as
    // '.' and the other dropped: the rightmost of the two decides, which is
    // what makes "1.234,56" and "1,234.56" the same figure. Nothing else is
    // touched, so a shape no locale writes still fails the check that follows.
    private static function pointedDecimal(string $unsigned, int $decimals): string
    {
        if ($decimals === 0) {
            // A yen has no decimal separator, so a '.' or ',' here can only be
            // a group mark -- but only where it groups: stripping one out of
            // "1250.00" would read a hundred times the figure rather than
            // refusing a shape this currency cannot hold.
            return preg_match('/^\d{1,3}([.,]\d{3})+$/', $unsigned) === 1
                ? str_replace(['.', ','], '', $unsigned)
                : $unsigned;
        }

        $lastDot = strrpos($unsigned, '.');
        $lastComma = strrpos($unsigned, ',');

        if ($lastDot !== false && $lastComma !== false) {
            return $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $unsigned)
                : str_replace(',', '', $unsigned);
        }

        return $lastComma !== false ? str_replace(',', '.', $unsigned) : $unsigned;
    }

    // Rejects zero as well as negatives, for inputs whose sign is fixed by
    // context — a split leg, a pot/goal/budget target.
    public static function tryToPositiveMinor(string $value, ?string $currencyCode = null): ?int
    {
        $minor = self::tryToMinor($value, $currencyCode);

        return $minor !== null && $minor > 0 ? $minor : null;
    }

    // -123456 -> "-1.234,56" for a Dutch reader and "-1,234.56" for an English
    // one: symbol-free, so a value shown then submitted untouched parses back
    // to the same minor units via tryToMinor().
    public static function formatMinor(int $minor, ?string $currencyCode = null): string
    {
        return ($minor < 0 ? '-' : '').self::formatAbsMinor($minor, $currencyCode);
    }

    // 123456 -> "1.234,56", for inputs that carry their sign separately.
    // Grouped because a five-figure balance in an input is read before it is
    // edited, and the marks are the reader's own, so the editable figure and
    // the read-only one beside it are written the same way.
    public static function formatAbsMinor(int $minor, ?string $currencyCode = null): string
    {
        $locale = MoneyText::language();
        $scale = CurrencyScale::minorUnitsPerMajor($currencyCode);
        $abs = abs($minor);
        $whole = MoneyText::group((string) intdiv($abs, $scale), $locale);

        if ($scale === 1) {
            return $whole;
        }

        return $whole.$locale->decimalMark()
            .str_pad((string) ($abs % $scale), CurrencyScale::decimalsOfScale($scale), '0', STR_PAD_LEFT);
    }

    // How many decimal places this currency's amounts actually take, so a
    // message about a rejected shape can describe the shape that is accepted
    // rather than the two-decimal one every currency was assumed to have.
    public static function decimalPlaces(?string $currencyCode = null): int
    {
        return CurrencyScale::decimals($currencyCode);
    }

    // The machine-readable form: "1234.56", no symbol and no group mark, for a
    // CSV cell or an API field where a reader's separators would be a bug. A
    // hundredth of a yen in an export is not a smaller number, it is a wrong
    // one, so the scale is the currency's own.
    public static function toDecimalString(int $minor, ?string $currencyCode = null): string
    {
        $scale = CurrencyScale::minorUnitsPerMajor($currencyCode);
        $abs = abs($minor);
        $sign = $minor < 0 ? '-' : '';

        if ($scale === 1) {
            return $sign.$abs;
        }

        return $sign.intdiv($abs, $scale).'.'
            .str_pad((string) ($abs % $scale), CurrencyScale::decimalsOfScale($scale), '0', STR_PAD_LEFT);
    }
}
