<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// One rule, in both directions: a part this release cannot decode makes the
// whole spec unreadable rather than a spec with a hole in it, and half a body
// in the reader's language with the other half missing reads worse than the
// whole stored sentence the caller falls back to.
/**
 * @link ../../../../.docs/features/notifications/reader-language-copy.md#compatibility-across-versions
 */
final class AllOrNothing
{
    /**
     * @template TKey of array-key
     * @template TItem
     * @template TValue
     *
     * @param  iterable<TKey, TItem>  $items
     * @param  callable(TItem): (TValue|null)  $decode
     * @return array<TKey, TValue>|null
     */
    public static function map(iterable $items, callable $decode): ?array
    {
        $decoded = [];
        foreach ($items as $key => $item) {
            $value = $decode($item);
            if ($value === null) {
                return null;
            }
            $decoded[$key] = $value;
        }

        return $decoded;
    }
}
