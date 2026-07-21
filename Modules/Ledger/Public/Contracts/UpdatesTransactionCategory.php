<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;

// The one path that mutates transactions.category_id. The
// Categorization module injects this interface; Ledger's
// UpdateTransactionCategory action is the default implementation.
// Returns rows affected (0 when not owned by the user; 1 on success).
interface UpdatesTransactionCategory
{
    public function __invoke(int $transactionId, ?int $categoryId, User $user): int;
}
