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
        $normalized = self::ungrouped(trim($raw));

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

    // PayPal renders NL locale, where the period groups thousands, and a
    // payment of a thousand or more was refused whole for carrying one.
    // Stripped only from a figure that actually groups in threes, so a stray
    // period is still a refusal rather than a hundred times the money.
    private static function ungrouped(string $raw): string
    {
        return preg_match('/^([+-]?)(\d{1,3}(?:\.\d{3})+)(,\d+)?$/', $raw, $m) === 1
            ? $m[1].str_replace('.', '', $m[2]).($m[3] ?? '')
            : $raw;
    }
}
