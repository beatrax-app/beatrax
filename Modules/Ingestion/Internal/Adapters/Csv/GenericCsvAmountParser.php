<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Csv;

use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ledger\Public\ValueObjects\CurrencyScale;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

final class GenericCsvAmountParser
{
    // U+2212 MINUS SIGN, U+2013 EN DASH, U+2014 EM DASH, U+FE63 and U+FF0D:
    // a figure that has been through a spreadsheet or a PDF carries one of
    // these where a hyphen-minus belongs, and the old character strip dropped
    // it silently -- so '−12.50' was booked as a credit of 12.50.
    private const array MINUS_GLYPHS = ["\u{2212}", "\u{2013}", "\u{2014}", "\u{FE63}", "\u{FF0D}"];

    private const array BLANKS = [' ', "\u{00A0}", "\u{202F}"];

    // The scale is the currency's own — a yen has no minor unit, so the
    // repo-wide hundred read ¥1.000 as ¥100.000 at the boundary that reads the
    // file. A caller with no currency to hand keeps the two-decimal assumption.
    public function parseMinor(string $cell, string $decimalSeparator, ?string $currencyCode = null): int
    {
        $raw = trim($cell);
        if ($raw === '') {
            throw new InvalidAmountException('Empty amount cell.');
        }

        $negative = false;
        if (preg_match('/^\((.*)\)$/', $raw, $m) === 1) {
            $negative = true;
            $raw = trim($m[1]);
        }

        $raw = str_replace(self::MINUS_GLYPHS, '-', $raw);
        $raw = str_replace([...self::BLANKS, ...array_values(Money::SYMBOLS)], '', $raw);

        [$sign, $intPart, $fracPart] = $this->digitsOrRefuse($raw, $decimalSeparator, $cell);
        if ($sign === '-') {
            $negative = true;
        }

        // Thrown rather than left to the (int) cast, which raises a TypeError
        // once the minor-unit multiplication leaves the 64-bit range.
        if (strlen($intPart) > MoneyInput::MAX_WHOLE_DIGITS) {
            throw new InvalidAmountException(sprintf("Amount out of range: '%s'.", $cell));
        }

        $scale = CurrencyScale::minorUnitsPerMajor($currencyCode);
        $decimals = CurrencyScale::decimals($currencyCode);

        // Round (not truncate) at the currency's last minor unit: read one
        // fractional digit past it and round on that digit. A carry
        // (e.g. 0.999 -> 100 cents) folds naturally into intPart*scale + units.
        $guard = substr($fracPart.str_repeat('0', $decimals + 1), 0, $decimals + 1);
        $units = (int) substr($guard, 0, $decimals);
        if ((int) substr($guard, $decimals, 1) >= 5) {
            $units++;
        }
        $minor = ((int) $intPart) * $scale + $units;

        return $negative ? -$minor : $minor;
    }

    // One notation and only one, the preset's own: digits either ungrouped or
    // grouped in threes by the separator the decimal one is not. Read loosely,
    // a '12,50' in a period-decimal file lost its comma and booked a hundred
    // times the money, and a '1.234,56' in one booked a thousandth of it.
    /**
     * @return array{0: string, 1: string, 2: string} sign, whole digits, fractional digits
     */
    private function digitsOrRefuse(string $raw, string $decimalSeparator, string $cell): array
    {
        $thousands = $decimalSeparator === ',' ? '.' : ',';
        $group = preg_quote($thousands, '/');
        $point = preg_quote($decimalSeparator, '/');

        if (preg_match('/^([+-]?)(\d{1,3}(?:'.$group.'\d{3})+|\d+)(?:'.$point.'(\d+))?$/', $raw, $m) !== 1) {
            throw new InvalidAmountException(sprintf("Cannot parse amount '%s'.", $cell));
        }

        return [$m[1], str_replace($thousands, '', $m[2]), $m[3] ?? ''];
    }
}
