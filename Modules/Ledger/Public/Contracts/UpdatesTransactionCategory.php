<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;

// The one path that mutates transactions.category_id. Returns rows affected:
// 0 when the row is not the user's, 1 on success.
interface UpdatesTransactionCategory
{
    public function __invoke(int $transactionId, ?int $categoryId, User $user): int;
}
