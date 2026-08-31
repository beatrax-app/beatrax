<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Number;
use IntlException;
use Modules\Core\Public\Enums\Locale;
use ValueError;

// The blade-facing number-formatting seam, the numeric counterpart to Lang:
// views @use this class and call Fmt::number(...) so a count or percentage
// picks up the active locale's grouping and decimal marks (en 1,234.5 vs
// nl 1.234,5). Amounts stay with Money, which carries the currency too.
final class Fmt
{
    private const string FALLBACK_DATE_PATTERN = 'DD-MM-YYYY';

    private const int COMPACT_FROM = 1000;

    public static function number(int|float $value, int $decimals = 0): string
    {
        $locale = Container::getInstance()->make(Translator::class)->getLocale();

        // The mobile build's ext-intl carries English-only ICU data, so on
        // device this throws for all twenty-five other languages — as
        // IntlException with error-exceptions on, ValueError otherwise. Why
        // that cannot be fixed by shipping data: .docs/features/mobile.
        try {
            $formatted = Number::format($value, precision: $decimals, locale: $locale);
        } catch (IntlException|ValueError) {
            $formatted = false;
        }

        return $formatted === false ? self::numberWithoutIcu($value, $decimals) : $formatted;
    }

    // Public for the reason Money::formatWithoutIcu() is: this is the arm both
    // phones take, and it is unreachable on a host with full ICU data, so
    // nothing would otherwise assert that it reads the same as the desktop.
    public static function numberWithoutIcu(int|float $value, int $decimals = 0): string
    {
        $marks = Locale::tryFrom(self::locale()) ?? Locale::En;

        // ICU rounds a half to even and keeps the sign of a figure that rounds
        // away to zero; number_format() rounds a half away from zero and drops
        // that sign, so 1280 bytes read "1,2 KB" on a desktop and "1,3 KB" on
        // the phone beside it.
        $rounded = is_int($value) ? $value : round($value, $decimals, \RoundingMode::HalfEven);
        $digits = number_format($rounded, $decimals, $marks->decimalMark(), $marks->groupMark());
        $negative = is_float($rounded) ? fdiv(1.0, $rounded) < 0 : $rounded < 0;

        return $negative ? $marks->minusSign().ltrim($digits, '-') : $digits;
    }

    // The locale's own short-date pattern, corrected where it writes the month
    // before the day. English is the only shipped locale that does, and it is
    // what a fresh install runs on, so 08/20/2026 is what a new reader met.
    public static function datePattern(): string
    {
        $pattern = CarbonImmutable::now()->getIsoFormats(self::locale())['L'] ?? self::FALLBACK_DATE_PATTERN;

        if (! is_string($pattern)) {
            return self::FALLBACK_DATE_PATTERN;
        }

        return self::dayBeforeMonth($pattern);
    }

    // Year-first locales are left alone: sv's 2026-08-20 is unambiguous, and
    // reordering it would be the odd thing to a Swedish reader.
    private static function dayBeforeMonth(string $pattern): string
    {
        $month = strpos($pattern, 'MM');
        $day = strpos($pattern, 'DD');

        if ($month === false || $day === false || $month > $day) {
            return $pattern;
        }

        $year = strpos($pattern, 'YYYY');

        if ($year !== false && $year < $month) {
            return $pattern;
        }

        // Both tokens are two characters, so swapping them in place keeps the
        // locale's own separators.
        return substr_replace(substr_replace($pattern, 'DD', $month, 2), 'MM', $day, 2);
    }

    // A badge count shortened so four digits cannot stretch the nav rail. The
    // tenth carries the locale's decimal mark: "1.2k" is one point two thousand
    // to an English reader and twelve hundred thousand to a Dutch one, whose
    // own mark for a tenth is the comma the money beside it already uses.
    public static function compactCount(int $value): string
    {
        if ($value < self::COMPACT_FROM) {
            return self::number($value);
        }

        $thousands = round($value / self::COMPACT_FROM, 1);

        return self::number($thousands, $thousands === floor($thousands) ? 0 : 1).'k';
    }

    // Every short date on screen, so the lists, the search results and the
    // date field cannot disagree about what day it is.
    public static function shortDate(CarbonInterface|string $when): string
    {
        $date = $when instanceof CarbonInterface
            ? CarbonImmutable::instance($when)
            : CarbonImmutable::parse($when);

        return $date->settings(['locale' => self::locale()])->isoFormat(self::datePattern());
    }

    private static function locale(): string
    {
        return Container::getInstance()->make(Translator::class)->getLocale();
    }
}
