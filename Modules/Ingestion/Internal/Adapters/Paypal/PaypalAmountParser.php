<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

/**
 * Pure parser that converts a PayPal NL-locale amount cell into signed
 * integer minor units. The Activity Download export this adapter
 * targets renders amounts as comma-decimal with two fractional digits
 * and an optional single leading sign — e.g. "-9,27", "10,46", "0,00".
 * No visible thousands separator appears in observed single-month
 * exports, so this parser stays strict on the simpler comma-decimal
 * shape.
 *
 * The parser is integer-only by construction: the regex captures the
 * whole and fractional groups and combines them via
 * `($whole * 100 + $fractional) * $sign`. No `(float)`, no `round()`,
 * no `intval` of a float — that is the reason "0,29" returns exactly 29
 * instead of the silent 28 that IEEE-754 would produce.
 *
 * Only leading and trailing whitespace is trimmed. Internal whitespace
 * is rejected — those shapes never appear in a real PayPal export and
 * accepting them would silently merge accidentally-concatenated cells.
 *
 * US-locale period-decimal (`"12.99"`) is rejected. PayPal exports
 * under an EN-locale account would carry that shape; a second profile-
 * aware parser arm will be added once such an account is observed,
 * rather than blanket-accepting both separator conventions and losing
 * the loud-failure property.
 */
final class PaypalAmountParser
{
    public function parseMinor(string $raw): int
    {
        $normalized = trim($raw);

        if (preg_match('/^([+-]?)(\d+),(\d{2})$/', $normalized, $m) !== 1) {
            throw new InvalidAmountException(sprintf(
                "Cannot parse PayPal amount: '%s' (expected NL-locale comma decimal with two fractional digits, e.g. '-12,99')",
                $raw,
            ));
        }

        $sign = $m[1] === '-' ? -1 : 1;
        $whole = (int) $m[2];
        $fractional = (int) $m[3];

        return $sign * ($whole * 100 + $fractional);
    }
}
