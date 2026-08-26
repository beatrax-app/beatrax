<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

final class PaypalAmountParser
{
    // Integer-only, mirroring BankAmountParser: no float cast, so "0,29" returns exactly 29.
    // US-locale period-decimal is rejected rather than accepting both separator conventions.
    // MAX_WHOLE_DIGITS bounds the whole part, so an over-range gross reaches
    // PaypalTransactionRollup as the InvalidAmountException its catch names.
    public function parseMinor(string $raw): int
    {
        $normalized = trim($raw);

        if (preg_match('/^([+-]?)(\d{1,'.MoneyInput::MAX_WHOLE_DIGITS.'}),(\d{2})$/', $normalized, $m) !== 1) {
            throw new InvalidAmountException(sprintf(
                "Cannot parse PayPal amount: '%s' (expected NL-locale comma decimal with two fractional digits, e.g. '-12,99')",
                $raw,
            ));
        }

        $sign = $m[1] === '-' ? -1 : 1;
        $whole = (int) $m[2];
        $fractional = (int) $m[3];

        return $sign * ($whole * Money::MINOR_UNITS_PER_MAJOR + $fractional);
    }
}
