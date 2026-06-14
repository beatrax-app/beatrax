<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge\Strategies;

use Modules\Sync\Internal\OpLog\OpLogEntry;

/**
 * Last-writer-wins (LWW) per-field merge strategy.
 *
 * Selects the entry with the highest HLC (last in the HLC-sorted ascending list)
 * and decodes its value. PHP null value is treated as the explicit clear/tombstone
 * sentinel — it writes SQL NULL to the target column.
 *
 * This is the default strategy for all standard mutable fields (category_id, note,
 * counterparty_id, type, etc.).
 */
final class LwwPerFieldStrategy implements MergeStrategyInterface
{
    /**
     * @param  list<OpLogEntry>  $candidateEntries  HLC-sorted ascending; last = winner.
     */
    public function resolve(array $candidateEntries): mixed
    {
        $lastIndex = count($candidateEntries) - 1;
        $winner = $candidateEntries[$lastIndex];

        if ($winner->value === null) {
            // Explicit SET NULL / clear sentinel — write SQL NULL to the column.
            return null;
        }

        return json_decode($winner->value, true, 512, JSON_THROW_ON_ERROR);
    }
}
