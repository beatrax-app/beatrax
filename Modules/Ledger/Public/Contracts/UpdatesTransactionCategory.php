<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;

/**
 * Public contract for the one path that mutates `transactions.category_id`.
 * The Categorization module injects this interface; Ledger's
 * `UpdateTransactionCategory` action is bound as the default implementation.
 */
interface UpdatesTransactionCategory
{
    /**
     * Set or unset the category on a transaction owned by the given user.
     * Returns the number of rows affected (0 when the transaction does not
     * belong to the user; 1 on success).
     */
    public function __invoke(int $transactionId, ?int $categoryId, User $user): int;
}
