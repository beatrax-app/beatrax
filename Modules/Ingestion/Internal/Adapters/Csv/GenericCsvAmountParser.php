<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Csv;

use Modules\Core\Public\Support\PatternScan;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ledger\Public\ValueObjects\CurrencyScale;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

final class GenericCsvAmountParser
{
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
            $raw = $m[1];
        }

        $thousands = $decimalSeparator === ',' ? '.' : ',';
        $raw = str_replace([$thousands, ' ', "\u{00A0}"], '', $raw);
        $raw = PatternScan::replace('/[^0-9'.preg_quote($decimalSeparator, '/').'+-]/u', '', $raw);

        if (str_starts_with($raw, '-')) {
            $negative = true;
        }
        $raw = ltrim($raw, '+-');
        $raw = str_replace($decimalSeparator, '.', $raw);

        if ($raw === '' || preg_match('/^\d+(\.\d*)?$/', $raw) !== 1) {
            throw new InvalidAmountException(sprintf("Cannot parse amount '%s'.", $cell));
        }

        [$intPart, $fracPart] = array_pad(explode('.', $raw, 2), 2, '');

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
}
