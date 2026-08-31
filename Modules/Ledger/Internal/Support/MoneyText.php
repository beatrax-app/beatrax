<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Enums\Locale;
use Modules\Ledger\Public\ValueObjects\Money;

// How a figure is written for the reader: the marks their locale groups and
// points with, and where the currency's symbol sits beside the digits. One
// home for it because Money writes the read-only figure and MoneyInput the
// editable one beside it, and their two copies of the grouping had drifted.
/**
 * @link ../../../../.docs/features/ledger/money-formatting.md
 */
final class MoneyText
{
    // The reader's locale, which is what decides separators and symbol
    // position; the currency decides only which symbol is placed. Anything
    // unrecognised reads as English rather than throwing mid-render.
    public static function language(): Locale
    {
        return Locale::tryFrom(Container::getInstance()->make(Translator::class)->getLocale()) ?? Locale::En;
    }

    // Chunked from the right by reversing the digits, then turned back before
    // the mark goes in: strrev() over the assembled string would split the two
    // bytes of a non-breaking space, which is the group mark in twelve of the
    // shipped locales.
    public static function group(string $whole, Locale $locale): string
    {
        $chunks = array_map(strrev(...), str_split(strrev($whole), 3));

        return implode($locale->groupMark(), array_reverse($chunks));
    }

    // A decimal figure written out in full, symbol included. The '.' in
    // $amount is the machine's decimal point, never the reader's, and the
    // leading '-' is the sign rather than a character to print: some locales
    // place it inside the symbol.
    public static function ofDecimal(string $amount, string $currencyCode): string
    {
        $locale = self::language();
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-')), 2, '');
        $digits = self::group($whole, $locale);

        if ($fraction !== '') {
            $digits .= $locale->decimalMark().$fraction;
        }

        return self::assemble($digits, str_starts_with($amount, '-'), $currencyCode, $locale);
    }

    private static function assemble(string $digits, bool $negative, string $currencyCode, Locale $locale): string
    {
        $known = Money::SYMBOLS[$currencyCode] ?? null;
        $symbol = $known ?? $currencyCode;
        $sign = $negative ? $locale->minusSign() : '';

        // ICU spaces an alphabetic symbol off the digits whatever the locale's
        // own pattern says, which is how a currency it has no sign for reads
        // "CHF 3,850.00" in English.
        $gap = $known === null ? "\u{00A0}" : $locale->symbolGap();

        if (! $locale->symbolBeforeAmount()) {
            return $sign.$digits.$gap.$symbol;
        }

        return $locale->signPrecedesSymbol()
            ? $sign.$symbol.$gap.$digits
            : $symbol.$gap.$sign.$digits;
    }
}
