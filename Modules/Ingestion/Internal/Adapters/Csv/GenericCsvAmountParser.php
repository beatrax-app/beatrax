<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Csv;

use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

/**
 * Parses a CSV amount cell into signed integer minor units (cents), driven
 * by the preset's decimal separator. Pure integer arithmetic — no float
 * path, which would corrupt cent precision.
 *
 * Handles the cross-bank zoo of amount spellings: `-1.234,56` (comma
 * decimal, dot thousands), `1,234.56` (dot decimal, comma thousands),
 * `1234.56`, `-12,34`, a leading `+`, parenthesised negatives `(12,34)`,
 * and stray currency symbols / non-breaking spaces. Assumes a two-decimal
 * minor unit (EUR/USD/GBP/etc.) — the only shapes these CSV exports use.
 */
final class GenericCsvAmountParser
{
    public function parseMinor(string $cell, string $decimalSeparator): int
    {
        $raw = trim($cell);
        if ($raw === '') {
            throw new InvalidAmountException('Empty amount cell.');
        }

        // Parenthesised negative, e.g. "(12,34)".
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $raw, $m) === 1) {
            $negative = true;
            $raw = $m[1];
        }

        // Strip everything that isn't a digit, the decimal separator, or a
        // sign (currency symbols, thousands separators, spaces, NBSPs).
        $thousands = $decimalSeparator === ',' ? '.' : ',';
        $raw = str_replace([$thousands, ' ', "\u{00A0}"], '', $raw);
        $raw = preg_replace('/[^0-9'.preg_quote($decimalSeparator, '/').'+-]/u', '', $raw) ?? '';

        if (str_starts_with($raw, '-')) {
            $negative = true;
        }
        $raw = ltrim($raw, '+-');
        $raw = str_replace($decimalSeparator, '.', $raw);

        if ($raw === '' || preg_match('/^[0-9]+(\.[0-9]*)?$/', $raw) !== 1) {
            throw new InvalidAmountException(sprintf("Cannot parse amount '%s'.", $cell));
        }

        [$intPart, $fracPart] = array_pad(explode('.', $raw, 2), 2, '');

        // Guard against integer overflow on absurd inputs (no real amount has
        // a 16+ digit integer part); fail with the domain exception rather
        // than letting the (int) cast raise a TypeError.
        if (strlen($intPart) > 15) {
            throw new InvalidAmountException(sprintf("Amount out of range: '%s'.", $cell));
        }

        // Round (not truncate) to two decimals: read the first three
        // fractional digits and round the cents on the third. A carry
        // (e.g. 0.999 → 100 cents) folds naturally into intPart*100 + cents.
        $frac3 = substr($fracPart.'000', 0, 3);
        $cents = (int) substr($frac3, 0, 2);
        if ((int) substr($frac3, 2, 1) >= 5) {
            $cents++;
        }
        $minor = ((int) $intPart) * 100 + $cents;

        return $negative ? -$minor : $minor;
    }
}
