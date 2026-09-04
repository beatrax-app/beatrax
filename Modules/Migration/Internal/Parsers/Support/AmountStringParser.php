<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;

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

    // A blank cell is the column this row did not use and a written zero is a
    // figure the file states; parse() answers null to both. Anything else null
    // is a value the reader wrote that could not be read, and a caller that
    // folds it into zero puts a wrong amount in the ledger saying nothing.
    public function requireMinor(string $value, string $context, ?string $currencyCode = null): int
    {
        $positive = $this->parse($value, $currencyCode);

        if ($positive !== null) {
            return $positive;
        }

        if (trim($value) === '' || $this->parseSigned($value, $currencyCode) === 0) {
            return 0;
        }

        throw new UnrecognizedMigrationFileException(
            "could not parse {$context} value '{$value}'",
        );
    }
}
