<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

/**
 * @link ../../../../../.docs/features/ingestion/architecture.md
 */
final class BankAmountParser
{
    // Integer-only by construction: whole + fractional groups combine via
    // ($whole * 100 + $fractional) * $sign, never a float cast — this is
    // why "0.29" returns exactly 29 rather than the silent 28 that
    // (int)((float) '0.29' * 100) would produce under 64-bit floating point.
    public function parseMinor(string $raw): int
    {
        $normalized = trim($raw);

        if (preg_match('/^([+-]?)(\d+)\.(\d{2})$/', $normalized, $m) !== 1) {
            throw new InvalidAmountException(sprintf(
                "Cannot parse amount: '%s' (expected period-decimal with two fractional digits, e.g. '-12.34')",
                $raw,
            ));
        }

        $sign = $m[1] === '-' ? -1 : 1;
        $whole = (int) $m[2];
        $fractional = (int) $m[3];

        return $sign * ($whole * 100 + $fractional);
    }
}
