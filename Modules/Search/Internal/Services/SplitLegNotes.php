<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\Query\Builder;

// The order a transaction's legs are read in, applied by both writers of one
// search body. They have disagreed about a note before — one took the first row
// it found, the other the last — and a rebuild overwrote a real note with a
// leg's null, so the ordering is stated once rather than at each of them.
final class SplitLegNotes
{
    // sort_order is the reader's own ordering and is reassigned on every save,
    // so it does not identify a leg on its own; the id breaks its ties.
    public static function ordered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
