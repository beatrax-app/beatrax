<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge\Strategies;

use Modules\Sync\Internal\OpLog\OpLogEntry;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final class OrSetStrategy implements MergeStrategyInterface
{
    // Used for fields like merchant_aliases.merged_from that hold a set of
    // values where concurrent adds and removes must converge: collect all
    // added {v, tag} pairs and removed tags across entries, then return
    // elements whose tag is in the add-set but NOT in the remove-set.
    /**
     * @param  list<OpLogEntry>  $candidateEntries  HLC-sorted ascending.
     * @return list<array{v: string, tag: string}> The merged set of live elements.
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

            $decoded = json_decode($entry->value, true, 512, JSON_THROW_ON_ERROR);

            // Validate the OR-Set wire shape explicitly and throw a typed error
            // on a malformed value, rather than relying on a downstream
            // string-offset TypeError — the replayer catches this and
            // quarantines the op with a meaningful 'strategy_error' reason.
            $added = is_array($decoded) ? ($decoded['added'] ?? null) : null;
            $removed = is_array($decoded) ? ($decoded['removed'] ?? null) : null;

            if (! is_array($added) || ! is_array($removed)) {
                throw new \UnexpectedValueException('Malformed OR-Set value: expected {added: [], removed: []}.');
            }

            foreach ($added as $item) {
                $tag = is_array($item) ? ($item['tag'] ?? null) : null;
                $value = is_array($item) ? ($item['v'] ?? null) : null;

                if (! is_string($tag) || ! is_string($value)) {
                    throw new \UnexpectedValueException('Malformed OR-Set add entry: expected {v: string, tag: string}.');
                }

                $addSet[$tag] = $value;
            }

            foreach ($removed as $removedTag) {
                if (! is_string($removedTag)) {
                    throw new \UnexpectedValueException('Malformed OR-Set remove tag: expected a string.');
                }

                $removeSet[$removedTag] = true;
            }
        }

        $result = [];

        foreach ($addSet as $tag => $value) {
            if (! isset($removeSet[$tag])) {
                $result[] = ['v' => $value, 'tag' => $tag];
            }
        }

        return $result;
    }
}
