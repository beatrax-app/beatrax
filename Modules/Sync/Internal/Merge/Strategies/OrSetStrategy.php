<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge\Strategies;

use Modules\Sync\Internal\OpLog\OpLogEntry;

/**
 * OR-Set (Observed-Remove Set) merge strategy.
 *
 * Used for fields like merchant_aliases.merged_from that hold a set of values
 * where concurrent adds and removes must converge correctly.
 *
 * Each op value is a JSON object:
 *   {"added": [{"v": "element", "tag": "device-id:hlcL:hlcC"}], "removed": ["tag1", "tag2"]}
 *
 * Merge algorithm (standard OR-Set / 2P-Set variant):
 *   1. Collect all added {v, tag} pairs from all entries into a global add-set.
 *   2. Collect all removed tags from all entries into a global remove-set.
 *   3. Return elements whose tag is in the add-set but NOT in the remove-set.
 *
 * This is convergent: an element removed on any device is excluded from the union
 * as long as the remove op references the element's original add tag.
 */
final class OrSetStrategy implements MergeStrategyInterface
{
    /**
     * @param  list<OpLogEntry>  $candidateEntries  HLC-sorted ascending.
     * @return list<array{v: string, tag: string}>  The merged set of live elements.
     */
    public function resolve(array $candidateEntries): mixed
    {
        /** @var array<string, string> $addSet  tag => element value */
        $addSet = [];

        /** @var array<string, true> $removeSet  tag => true */
        $removeSet = [];

        foreach ($candidateEntries as $entry) {
            if ($entry->value === null) {
                continue;
            }

            /** @var array{added: list<array{v: string, tag: string}>, removed: list<string>} $decoded */
            $decoded = json_decode($entry->value, true, 512, JSON_THROW_ON_ERROR);

            foreach ($decoded['added'] as $item) {
                $addSet[$item['tag']] = $item['v'];
            }

            foreach ($decoded['removed'] as $removedTag) {
                $removeSet[$removedTag] = true;
            }
        }

        // Result: elements whose tag is in the add-set but not in the remove-set.
        $result = [];

        foreach ($addSet as $tag => $value) {
            if (! isset($removeSet[$tag])) {
                $result[] = ['v' => $value, 'tag' => $tag];
            }
        }

        return $result;
    }
}
