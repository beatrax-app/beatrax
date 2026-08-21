<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\Query\Builder;

// The keyset cursor every transaction list pages on. A row-value comparison
// rather than an id alone, because two transactions can share a posted_at and
// the descending sort would otherwise repeat or skip one across the boundary.
final class TransactionCursor
{
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
