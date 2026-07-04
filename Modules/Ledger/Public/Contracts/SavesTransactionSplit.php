<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Ledger\Public\Exceptions\SplitSumMismatchException;

/**
 * Public contract for the sole mutator of `transaction_splits`. Enforces the
 * sum-to-parent Money-VO invariant inside the DB write transaction (Req 1,
 * D-04), the non-transfer type gate (Req 6), the non-zero/same-sign leg
 * rules (Req 7), the category visibility guard (per-leg), and the
 * PK-preserving edit diff (Req 10, D-09, D-12).
 *
 * Leg shape consumed by `save()`:
 *   [
 *       'id' => ?int,               // present + matches an existing leg id => UPDATE in place,
 *                                   // preserving that row's primary key.
 *                                   // null/absent, or an id not among existing legs => INSERT.
 *       'category_id' => int,       // required — visibility-guarded per leg.
 *       'settled_amount_minor' => int,
 *       'note' => ?string,
 *   ]
 *
 * A leg present among the existing rows for the transaction but absent from
 * the incoming set is DELETEd. Regenerating leg primary keys on every save
 * (delete-all+reinsert) is forbidden — it breaks the per-(table, pk, field)
 * LWW sync merge (D-09, D-12).
 */
interface SavesTransactionSplit
{
    /**
     * Create a new split, or edit an existing one, for the transaction owned
     * by $user. Requires at least 2 legs. Rejects transfer_in/transfer_out
     * parents, zero-amount legs, opposite-sign legs, legs whose category is
     * not visible to $user, and any leg set whose sum does not exactly equal
     * the parent's settled_amount_minor.
     *
     * @param  list<array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}>  $legs
     *
     * @throws SplitSumMismatchException
     */
    public function save(User $user, int $transactionId, array $legs): void;

    /**
     * Reverse a split: delete all leg rows for the transaction and set
     * transactions.category_id to $survivingCategoryId (Req 8, D-09).
     */
    public function unsplit(User $user, int $transactionId, int $survivingCategoryId): void;
}
