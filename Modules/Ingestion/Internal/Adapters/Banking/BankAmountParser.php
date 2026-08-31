<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ledger\Public\ValueObjects\CurrencyScale;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

final class BankAmountParser
{
    // Integer-only, never a float cast: (int)((float) '0.29' * 100) yields a
    // silent 28 under 64-bit floating point, where this yields exactly 29. The
    // scale is the currency's own — a yen has no minor unit — and falls back to
    // a hundredth for a caller with no currency to hand.
    public function parseMinor(string $raw, ?string $currencyCode = null): int
    {
        $scale = CurrencyScale::minorUnitsPerMajor($currencyCode);
        $decimals = CurrencyScale::decimals($currencyCode);
        $normalized = trim($raw);

        // MAX_WHOLE_DIGITS bounds the whole part, so an amount wider than the
        // ledger holds is refused rather than overflowing the int return type.
        $fraction = $decimals === 0 ? '' : '\.(\d{'.$decimals.'})';
        if (preg_match('/^([+-]?)(\d{1,'.MoneyInput::MAX_WHOLE_DIGITS.'})'.$fraction.'$/', $normalized, $m) !== 1) {
            throw new InvalidAmountException(sprintf(
                "Cannot parse amount: '%s' (expected period-decimal at this currency's scale, e.g. '%s')",
                $raw,
                MoneyInput::toDecimalString(-1234, $currencyCode),
            ));
        }

        $sign = $m[1] === '-' ? -1 : 1;
        $whole = (int) $m[2];
        $fractional = $decimals === 0 ? 0 : (int) $m[3];

        return $sign * ($whole * $scale + $fractional);
    }

    // MT940 writes the decimal as a comma, and SWIFT's 15d amount may carry no
    // fractional digits after it or fewer than the currency has — four shapes
    // parseMinor() refuses. Both the :60:/:62: balance and the :61: line reach
    // it through here.
    public function parseMt940Minor(string $raw, ?string $currencyCode = null): int
    {
        $decimals = CurrencyScale::decimals($currencyCode);
        $normalised = str_replace(',', '.', trim($raw));

        $separator = strpos($normalised, '.');
        if ($separator === false) {
            $normalised .= $decimals === 0 ? '' : '.'.str_repeat('0', $decimals);
        } elseif ($decimals > 0) {
            // str_pad never truncates, so a fraction wider than the currency
            // holds survives to be refused rather than silently rounded away.
            $normalised = substr($normalised, 0, $separator)
                .'.'.str_pad(substr($normalised, $separator + 1), $decimals, '0');
        } elseif (substr($normalised, $separator + 1) === '') {
            $normalised = substr($normalised, 0, $separator);
        }

        return $this->parseMinor($normalised, $currencyCode);
    }
}
