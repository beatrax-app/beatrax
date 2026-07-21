<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Exceptions\SplitSumMismatchException;

/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
interface SavesTransactionSplit
{
    // A leg with an id matching an existing leg is UPDATEd in place,
    // preserving that row's primary key; null/absent or an unmatched
    // id is INSERTed. A leg present in the existing set but absent from
    // the incoming set is DELETEd — never a delete-all+reinsert.
    /**
     * @param  list<array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}>  $legs
     *
     * @throws SplitSumMismatchException leg totals do not sum to the parent exactly
     * @throws InvalidArgumentException transaction not found/not owned, non-splittable type,
     *                                  fewer than 2 legs, a zero/opposite-sign leg, or a leg
     *                                  category not visible to $user
     */
    public function save(User $user, int $transactionId, array $legs): void;

    /**
     * @throws InvalidArgumentException transaction not found/not owned, the surviving category
     *                                  is not visible to $user, or (when legs still exist) is
     *                                  not one of the split's current leg categories
     */
    public function unsplit(User $user, int $transactionId, int $survivingCategoryId): void;
}
