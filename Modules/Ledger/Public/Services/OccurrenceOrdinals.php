<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Modules\Ledger\Public\Dto\CanonicalTransaction;

// Which occurrence of an otherwise identical row this one is, within a single
// file. A bank states no time of day, so a statement that books the same
// purchase twice hands the ledger two rows agreeing in every column the dedup
// tuple reads, and the second was dropped as a duplicate of the first.
/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#the-occurrence-ordinal
 */
final class OccurrenceOrdinals
{
    /** @var array<string, int> group => how many rows of it this file has already produced */
    private array $counted = [];

    public function __construct(private readonly FingerprintComposer $fingerprints) {}

    // Counted over the rows of one file in the order that file lists them, and
    // never over the ledger: an ordinal read off what is already stored would
    // number a re-import 2 and 3 and write the reader's coffees a second time.
    public function stamp(CanonicalTransaction $tx): CanonicalTransaction
    {
        $group = $this->fingerprints->occurrenceGroup($tx);
        $ordinal = $this->counted[$group] ?? 0;
        $this->counted[$group] = $ordinal + 1;

        return $tx->withOccurrenceOrdinal($ordinal);
    }
}
