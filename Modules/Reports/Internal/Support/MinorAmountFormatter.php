<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Support;

/**
 * Formats a signed minor-unit integer amount as an unsigned, locale-neutral,
 * two-decimal-place string (e.g. `-7_500` -> `"75.00"`) without ever routing
 * the value through a float division (IN-01 — CLAUDE.md "What NOT to Use":
 * "Plain floats / cents-as-int for money"). The fractional part is computed
 * via integer modulo, never `/100` float division, so there is no
 * floating-point precision risk regardless of magnitude.
 *
 * Deliberately does NOT route through `Modules\Ledger\Public\ValueObjects\Money`
 * here: `Money::format()` renders a locale + currency-symbol string (e.g.
 * `"€ 75,00"` for EUR), which is the wrong shape for a CSV numeric column
 * that already carries the currency in its own column
 * (`ReportCsvExporter`) — this helper produces the locale-neutral decimal
 * string CSV consumers expect, matching the existing pinned output format.
 */
final class MinorAmountFormatter
{
    /**
     * @return string Unsigned decimal string with exactly 2 fraction digits, e.g. "75.00".
     */
    public static function toUnsignedDecimalString(int $minorAmount): string
    {
        $absMinor = abs($minorAmount);
        $whole = intdiv($absMinor, 100);
        $fraction = $absMinor % 100;

        return sprintf('%d.%02d', $whole, $fraction);
    }
}
