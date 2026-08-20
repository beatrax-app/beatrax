<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Modules\Ingestion\Public\Exceptions\InvalidAmountException;
use Modules\Ledger\Public\ValueObjects\Money;

final class IcsAmountParser
{
    public function parse(string $raw): int
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new InvalidAmountException('Empty amount string.');
        }

        // A closed list of ISO alpha-3 codes, not \b[A-Z]{3}\b, which would eat a non-currency token.
        $stripped = preg_replace('/[€$£¥]|\b(?:EUR|USD|GBP|JPY|CHF|CAD|AUD)\b/u', '', $trimmed);
        if ($stripped === null) {
            throw new InvalidAmountException(sprintf('Invalid amount string: %s', $raw));
        }
        $stripped = trim($stripped);

        // The body table prints no minus at all — the Af/Bij marker carries the sign there.
        $sign = 1;
        if (str_starts_with($stripped, '-')) {
            $sign = -1;
            $stripped = substr($stripped, 1);
        } elseif (str_ends_with($stripped, '-')) {
            $sign = -1;
            $stripped = substr($stripped, 0, -1);
        }
        $stripped = trim($stripped);

        // Dutch convention: comma decimal, period thousands.
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

        return $sign * ($whole * Money::MINOR_UNITS_PER_MAJOR + $fractional);
    }
}
