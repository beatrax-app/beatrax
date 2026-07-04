<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\UpdatesTransactionCategory;

/**
 * Mutates `transactions.category_id` for a transaction owned by the given
 * user. Exposed as a Public contract so the Categorization module can drive
 * category assignment without reaching into the Ledger model directly.
 *
 * Defence in depth: the transaction is filtered by `(id, user_id)` AND the
 * category — when not null — must either belong to the user or be a global
 * default-tree row (`categories.user_id IS NULL`). Without the second
 * check, the FK alone would accept any `categories.id` from another user
 * because the FK target is the table, not the row's owner. A foreign
 * category id would render as "uncategorized" in the picker (the Category
 * global scope hides it) but the raw column would still hold a
 * cross-tenant reference visible to any consumer that reads it directly.
 *
 * D-08 reconciled lock: this is the single writer behind every category-edit
 * entry point (AssignCategory, InlineCategoryPicker, TransactionDetail's
 * reclassifyCategory), so the warn-first "un-reconcile first" guard lives
 * here rather than being duplicated per caller — a reconciled row's category
 * can never change regardless of which surface drives the write. Mirrors
 * the same status check TransactionDetail's guarded mutators use (WR-01).
 */
final class UpdateTransactionCategory implements UpdatesTransactionCategory
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function __invoke(int $transactionId, ?int $categoryId, User $user): int
    {
        $status = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->value('status');

        if ($status === 'reconciled') {
            return 0;
        }

        if ($categoryId !== null) {
            $categoryVisible = $this->db->connection()
                ->table('categories')
                ->where('id', $categoryId)
                ->where(static function (QueryBuilder $q) use ($user): void {
                    $q->whereNull('user_id')->orWhere('user_id', $user->id);
                })
                ->exists();

            if (! $categoryVisible) {
                return 0;
            }
        }

        return Transaction::query()
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->update(['category_id' => $categoryId]);
    }
}
