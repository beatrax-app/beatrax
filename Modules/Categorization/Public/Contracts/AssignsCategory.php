<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Contracts;

use Modules\Core\Models\User;

/**
 * Public Categorization contract — UI surfaces (the inline picker on the
 * transactions list and the triage inbox) inject this to assign or clear
 * the category on a transaction. Implementations route writes through the
 * Ledger Public surface so Ledger remains the single mutator of
 * `transactions.category_id` (D-04), and fire `TransactionCategorized`
 * after a successful write.
 */
interface AssignsCategory
{
    /**
     * Assigns $categoryId to $transactionId, or NULL to un-categorize.
     * Returns the number of rows affected (0 when the transaction is not
     * the user's; 1 on success). The event fires only when affected > 0.
     */
    public function __invoke(int $transactionId, ?int $categoryId, User $user): int;
}
