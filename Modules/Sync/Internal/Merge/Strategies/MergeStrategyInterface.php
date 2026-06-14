<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge\Strategies;

use Modules\Sync\Internal\OpLog\OpLogEntry;

interface MergeStrategyInterface
{
    /**
     * Resolve a set of candidate op-log entries for a single (table, pk, field)
     * triple into the decoded value to write to the DB column.
     *
     * @param  list<OpLogEntry>  $candidateEntries  All entries for this (table, pk, field),
     *                                               HLC-sorted ascending. Last entry = highest HLC.
     * @return mixed  The decoded value to write to the DB column (already json_decode'd).
     *               Returning null writes SQL NULL to the column.
     */
    public function resolve(array $candidateEntries): mixed;
}
