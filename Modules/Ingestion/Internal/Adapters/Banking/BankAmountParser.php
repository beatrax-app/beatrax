<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

final class BankAmountParser
{
    // Integer-only, never a float cast: (int)((float) '0.29' * 100) yields a
    // silent 28 under 64-bit floating point, where this yields exactly 29.
    // The whole part is bounded by the same MAX_WHOLE_DIGITS the ledger holds,
    // so a wider amount is refused here rather than overflowing the minor-unit
    // multiplication into a float the int return type rejects.
    public function parseMinor(string $raw): int
    {
        $normalized = trim($raw);

        if (preg_match('/^([+-]?)(\d{1,'.MoneyInput::MAX_WHOLE_DIGITS.'})\.(\d{2})$/', $normalized, $m) !== 1) {
            throw new InvalidAmountException(sprintf(
                "Cannot parse amount: '%s' (expected period-decimal with two fractional digits, e.g. '-12.34')",
                $raw,
            ));
        }

        $sign = $m[1] === '-' ? -1 : 1;
        $whole = (int) $m[2];
        $fractional = (int) $m[3];

        return $sign * ($whole * Money::MINOR_UNITS_PER_MAJOR + $fractional);
    }

    // MT940 writes the decimal as a comma, and a whole amount may carry no
    // decimal at all or only a single digit — three shapes parseMinor() refuses.
    // Both the :60:/:62: balance and the :61: line reach it through here.
    public function parseMt940Minor(string $raw): int
    {
        $normalised = str_replace(',', '.', $raw);
        if (! str_contains($normalised, '.')) {
            $normalised .= '.00';
        } elseif (preg_match('/\.\d$/', $normalised) === 1) {
            $normalised .= '0';
        }

        return $this->parseMinor($normalised);
    }
}
