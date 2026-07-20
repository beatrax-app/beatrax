<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge\Strategies;

use Modules\Sync\Internal\OpLog\OpLogEntry;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
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
            return null;
        }

        return json_decode($winner->value, true, 512, JSON_THROW_ON_ERROR);
    }
}
