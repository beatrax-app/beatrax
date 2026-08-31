<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Ledger\Public\ValueObjects\MoneyInput;

// A YNAB cell is read the same way a typed amount is, and the two drifted apart
// once already — the pinning test caught it at the magnitude ceiling. The
// currency is the reader's own, since neither export states one: a yen cell
// carries no minor unit, and reading it at a hundredth is a hundredfold error.
final class AmountStringParser
{
    public function parse(string $value, ?string $currencyCode = null): ?int
    {
        return MoneyInput::tryToPositiveMinor($value, $currencyCode);
    }

    // A Budget.csv cell, where an explicit zero and a negative are both figures
    // the reader wrote: parse() nulls those two along with the blank cell, and
    // "the file says zero" is not "the file says nothing".
    public function parseSigned(string $value, ?string $currencyCode = null): ?int
    {
        return MoneyInput::tryToMinor($value, $currencyCode);
    }
}
