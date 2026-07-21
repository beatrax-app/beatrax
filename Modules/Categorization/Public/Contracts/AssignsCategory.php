<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Contracts;

use Modules\Core\Models\User;

// Implementations route writes through the Ledger Public surface so
// Ledger remains the single mutator of `transactions.category_id`, and
// fire TransactionCategorized after a successful write.
interface AssignsCategory
{
    // Assigns $categoryId, or NULL to un-categorize. Returns rows affected
    // (0 when the transaction is not the user's; 1 on success); the event
    // fires only when affected > 0.
    public function __invoke(int $transactionId, ?int $categoryId, User $user): int;
}
