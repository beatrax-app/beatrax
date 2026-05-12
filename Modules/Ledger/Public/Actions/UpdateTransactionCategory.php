<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\UpdatesTransactionCategory;

/**
 * Mutates `transactions.category_id` for a transaction owned by the given
 * user. Exposed as a Public contract so the Categorization module can drive
 * category assignment without reaching into the Ledger model directly.
 */
final class UpdateTransactionCategory implements UpdatesTransactionCategory
{
    public function __invoke(int $transactionId, ?int $categoryId, User $user): int
    {
        return Transaction::query()
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->update(['category_id' => $categoryId]);
    }
}
