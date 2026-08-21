<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Contracts;

use Modules\Core\Models\User;

// Implementations write through the Ledger Public surface, so Ledger stays the
// single mutator of `transactions.category_id`.
interface AssignsCategory
{
    // A null $categoryId un-categorises. Returns rows affected — 0 when the
    // transaction is not the user's — and the event fires only when it is not.
    public function __invoke(int $transactionId, ?int $categoryId, User $user): int;
}
