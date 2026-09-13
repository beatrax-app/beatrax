<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Csv;

use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;

// The refusals a header-named CSV row earns for its shape alone, before any
// cell is read as a date or a figure.
final class CsvRowShape
{
    // league/csv maps a record onto the header by offset, so a row carrying
    // more cells than the header names loses the surplus and one carrying
    // fewer reads those columns as empty — both without a word. A column past
    // the header's last is filled only by a row that ran past it.
    public const string RAN_PAST_THE_HEADER = "\0beyond-the-header";

    // A row that does not line up with the header is refused rather than read
    // through it: one cell of an unescaped delimiter shifts every column after
    // it, and the amount then comes off whichever column landed there.
    /**
     * @param  array<string, string|null>  $record
     */
    public static function refuseARowThatIsNotTheHeadersWidth(array $record, bool $ranPastTheHeader, int $index): void
    {
        if (! self::carriesData($record)) {
            return;
        }

        if ($ranPastTheHeader) {
            throw new InvalidAmountException(sprintf(
                'Row %d carries more cells than the %d columns the header names.',
                $index,
                count($record),
            ));
        }

        foreach ($record as $column => $value) {
            if ($value === null) {
                throw new InvalidAmountException(sprintf(
                    "Row %d stops before the '%s' column the header names.",
                    $index,
                    $column,
                ));
            }
        }
    }

    // The sibling positional reader refuses an undated row through parseDate(),
    // and this one skipped it -- so an ING row carrying an amount, a
    // counterparty and a description but no date left the import with nothing
    // said, and the rows after it renumbered over the gap.
    /**
     * @param  array<string, string|null>  $record
     */
    public static function refuseAnUndatedRowThatCarriesData(array $record, string $dateHeader, int $index): void
    {
        if (self::carriesData($record)) {
            throw new InvalidAmountException(sprintf(
                "Row %d: the '%s' column is empty, and the row is not.",
                $index,
                $dateHeader,
            ));
        }
    }

    // A trailing blank line, or a filler row a bank's export writes between
    // sections: nothing to read and nothing to refuse.
    /**
     * @param  array<string, string|null>  $record
     */
    private static function carriesData(array $record): bool
    {
        foreach ($record as $value) {
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }
}
