<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Enums\Locale;

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

    // Shape only, with no MAX_MINOR ceiling applied, so exceedsMax() can tell
    // a figure that is merely too large from one that is not an amount at all.
    private static function parseAnyMagnitude(string $value): ?int
    {
        $trimmed = str_replace(self::GROUP_MARKS, '', trim($value));
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

        if (preg_match('/^\d{1,'.self::MAX_WHOLE_DIGITS.'}(\.\d{1,2})?$/', $unsigned) !== 1) {
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

    // -123456 -> "-1.234,56" for a Dutch reader and "-1,234.56" for an English
    // one: symbol-free, so a value shown then submitted untouched parses back
    // to the same minor units via tryToMinor().
    public static function formatMinor(int $minor): string
    {
        return ($minor < 0 ? '-' : '').self::formatAbsMinor($minor);
    }

    // 123456 -> "1.234,56", for inputs that carry their sign separately.
    // Grouped because a five-figure balance in an input is read before it is
    // edited, and the marks are the reader's own, so the editable figure and
    // the read-only one beside it are written the same way.
    public static function formatAbsMinor(int $minor): string
    {
        $locale = self::language();
        $abs = abs($minor);
        $cents = str_pad((string) ($abs % Money::MINOR_UNITS_PER_MAJOR), 2, '0', STR_PAD_LEFT);

        return self::group((string) intdiv($abs, Money::MINOR_UNITS_PER_MAJOR), $locale)
            .$locale->decimalMark()
            .$cents;
    }

    // Each chunk is reversed back before the mark goes in: reversing the
    // assembled string would split the two bytes of a non-breaking space,
    // which is the group mark in twelve of the shipped locales.
    private static function group(string $whole, Locale $locale): string
    {
        $chunks = array_map(strrev(...), str_split(strrev($whole), 3));

        return implode($locale->groupMark(), array_reverse($chunks));
    }

    // The reader's active language, which is what decides the two marks. An
    // unrecognised code reads as English rather than throwing mid-render, the
    // same fallback Money::format() takes.
    private static function language(): Locale
    {
        return Locale::tryFrom(Container::getInstance()->make(Translator::class)->getLocale()) ?? Locale::En;
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
