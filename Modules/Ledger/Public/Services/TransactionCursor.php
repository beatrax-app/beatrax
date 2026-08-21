<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\Query\Builder;

// The keyset cursor every transaction list pages on. A row-value comparison
// rather than an id alone, because two transactions can share a posted_at and
// the descending sort would otherwise repeat or skip one across the boundary.
final class TransactionCursor
{
    // The other half of the same contract: the sort the row-value comparison
    // below pages against. A query that orders on posted_at alone, or that
    // breaks the tie the other way, hands back rows the cursor then skips.
    public static function orderNewestFirst(Builder $query): void
    {
        $query->orderByDesc('transactions.posted_at')->orderByDesc('transactions.id');
    }

    public static function apply(Builder $query, ?string $cursorPostedAt, ?int $cursorId): void
    {
        if ($cursorId === null) {
            return;
        }

        if ($cursorPostedAt === null) {
            // Legacy single-id cursor, kept for backwards compatibility;
            // callers should supply the pair for correct posted_at-tie ordering.
            $query->where('transactions.id', '<', $cursorId);

            return;
        }

        $query->whereRaw(
            '(transactions.posted_at, transactions.id) < (?, ?)',
            [$cursorPostedAt, $cursorId],
        );
    }
}
