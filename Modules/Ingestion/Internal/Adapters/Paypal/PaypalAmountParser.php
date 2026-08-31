<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ledger\Public\ValueObjects\CurrencyScale;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

final class PaypalAmountParser
{
    // Integer-only, mirroring BankAmountParser: no float cast, so "0,29" returns exactly 29.
    // US-locale period-decimal is rejected rather than accepting both separator conventions.
    // The scale is the currency of the row being read, so a JPY gross is not
    // read as a hundred times itself.
    public function parseMinor(string $raw, ?string $currencyCode = null): int
    {
        $scale = CurrencyScale::minorUnitsPerMajor($currencyCode);
        $decimals = CurrencyScale::decimals($currencyCode);
        $normalized = trim($raw);

        // MAX_WHOLE_DIGITS bounds the whole part, so an over-range gross reaches
        // PaypalTransactionRollup as the InvalidAmountException its catch names.
        $fraction = $decimals === 0 ? '' : ',(\d{'.$decimals.'})';
        if (preg_match('/^([+-]?)(\d{1,'.MoneyInput::MAX_WHOLE_DIGITS.'})'.$fraction.'$/', $normalized, $m) !== 1) {
            throw new InvalidAmountException(sprintf(
                "Cannot parse PayPal amount: '%s' (expected NL-locale comma decimal at this currency's scale, e.g. '%s')",
                $raw,
                str_replace('.', ',', MoneyInput::toDecimalString(-1299, $currencyCode)),
            ));
        }

        $sign = $m[1] === '-' ? -1 : 1;
        $whole = (int) $m[2];
        $fractional = $decimals === 0 ? 0 : (int) $m[3];

        return $sign * ($whole * $scale + $fractional);
    }
}
