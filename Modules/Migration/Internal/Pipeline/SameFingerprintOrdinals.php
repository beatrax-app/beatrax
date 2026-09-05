<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Modules\Core\Public\Concerns\CoercesScalars;
use stdClass;

// No budget export carries a time of day, so a promoted row's booked_at is its
// posting date plus an offset, and that offset keeps two same-day rows off one
// fingerprint. Counted over the tuple the fingerprint is built from, and never
// from the staging row's database id: that id is minted per run.
/**
 * @link ../../../../.docs/features/migration/architecture.md#a-row-re-exported-under-a-new-identity
 */
final class SameFingerprintOrdinals
{
    use CoercesScalars;

    /** @var array<string, int> */
    private array $handedOut = [];

    // Called for every staged row a run walks, including the ones it skips as
    // already mapped: the row's ordinal is its position among its own kind in
    // the export, and that only holds if the rows ahead of it are counted.
    public function next(stdClass $row): int
    {
        $tuple = $this->tuple($row);

        return $this->handedOut[$tuple] = ($this->handedOut[$tuple] ?? -1) + 1;
    }

    // The staged spelling of what FingerprintComposer keys on, read off the
    // source so two devices importing one export group the rows alike. The
    // category is deliberately absent: it is the column the reader edits before
    // re-exporting, and this tuple exists to survive that edit.
    private function tuple(stdClass $row): string
    {
        return implode("\x1f", [
            self::toString($row->account_source_external_id),
            self::toString($row->posted_at),
            is_string($row->payee_source_external_id) ? $row->payee_source_external_id : '',
            (string) self::toInt($row->amount_minor),
            self::toString($row->currency),
        ]);
    }
}
