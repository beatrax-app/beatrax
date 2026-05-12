<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn;

use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

/**
 * Pure parser that converts an ASN amount cell into signed integer minor
 * units. ASN's amount format is period-decimal with two fractional digits
 * and an optional single leading sign — e.g. "-12.34", "0.29", "1234567.89".
 *
 * The parser is integer-only by construction: the regex captures the whole
 * and fractional groups and combines them via
 * `($whole * 100 + $fractional) * $sign`. No `(float)`, no `round()`, no
 * `intval` of a float. That is the reason "0.29" returns exactly 29 instead
 * of the silent 28 that `(int)((float) '0.29' * 100)` would produce on
 * 64-bit IEEE-754.
 *
 * Only leading and trailing whitespace is trimmed. Internal whitespace
 * (between the sign and the digits, or anywhere inside the digit run) is
 * rejected — those shapes never appear in a real ASN export and accepting
 * them would silently merge accidentally-concatenated cells.
 */
final class AsnAmountParser
{
    public function parseMinor(string $raw): int
    {
        $normalized = trim($raw);

        if (preg_match('/^([+-]?)(\d+)\.(\d{2})$/', $normalized, $m) !== 1) {
            throw new InvalidAmountException(sprintf(
                "Cannot parse ASN amount: '%s' (expected period-decimal with two fractional digits, e.g. '-12.34')",
                $raw,
            ));
        }

        $sign = $m[1] === '-' ? -1 : 1;
        $whole = (int) $m[2];
        $fractional = (int) $m[3];

        return $sign * ($whole * 100 + $fractional);
    }
}
