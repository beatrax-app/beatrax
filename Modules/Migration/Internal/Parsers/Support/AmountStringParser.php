<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Ledger\Public\ValueObjects\MoneyInput;

// A YNAB cell is read the same way a typed amount is, and the two drifted apart
// once already — the pinning test caught it at the magnitude ceiling.
final class AmountStringParser
{
    public function parse(string $value): ?int
    {
        return MoneyInput::tryToPositiveMinor($value);
    }
}
