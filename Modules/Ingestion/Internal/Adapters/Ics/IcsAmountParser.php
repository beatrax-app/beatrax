<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

/**
 * Parses ICS PDF amount cells into signed integer minor units.
 *
 * Supported shapes (drawn from the Mijn ICS consumer-portal statement
 * fixture record under Modules/Ingestion/tests/fixtures/ics/):
 *
 *   - `€ 22,75`       → 2275      (EUR prefix-symbol, comma decimal)
 *   - `-€ 22,75`      → -2275     (negative prefix variant)
 *   - `$ 12,99`       → 1299      (ISO symbol fallback variant)
 *   - `12,99 USD`     → 1299      (ISO-code suffix variant, non-EUR)
 *   - `1.416,50`      → 141650    (period thousands, comma decimal)
 *
 * The parser is integer-only by construction — no `(float)`, no
 * `round()`. Stateless and free of global locale mutation; the locale
 * arch tests never trip.
 *
 * Sign handling: ICS statements do not render a leading `-` on debit
 * rows in the body of the table (the `Af` column marker carries the
 * sign). The parser still tolerates a leading or trailing `-` for the
 * summary header amounts that may have been pre-signed by the adapter
 * before reaching this helper.
 */
final class IcsAmountParser
{
    public function parse(string $raw): int
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new InvalidAmountException('Empty amount string.');
        }

        // Strip currency symbols and the ISO 4217 alpha-3 codes the
        // project's ingestion paths actually emit. The constrained set
        // matches the supported display currencies; a naive `\b[A-Z]{3}\b`
        // would consume any three-uppercase token (which the parser
        // never sees today, but a defensive narrower regex is cheap).
        $stripped = preg_replace('/[€$£¥]|\b(?:EUR|USD|GBP|JPY|CHF|CAD|AUD)\b/u', '', $trimmed);
        if ($stripped === null) {
            throw new InvalidAmountException(sprintf('Invalid amount string: %s', $raw));
        }
        $stripped = trim($stripped);

        // Sign normalisation: accept prefix or suffix minus.
        $sign = 1;
        if (str_starts_with($stripped, '-')) {
            $sign = -1;
            $stripped = substr($stripped, 1);
        } elseif (str_ends_with($stripped, '-')) {
            $sign = -1;
            $stripped = substr($stripped, 0, -1);
        }
        $stripped = trim($stripped);

        // Dutch decimal: `,` is the decimal separator, `.` is thousands.
        $stripped = str_replace('.', '', $stripped);
        $parts = explode(',', $stripped);

        if (
            count($parts) !== 2
            || ! ctype_digit($parts[0])
            || ! ctype_digit($parts[1])
            || strlen($parts[1]) !== 2
        ) {
            throw new InvalidAmountException(sprintf('Invalid Dutch amount format: %s', $raw));
        }

        $whole = (int) $parts[0];
        $fractional = (int) $parts[1];

        return $sign * ($whole * 100 + $fractional);
    }
}
