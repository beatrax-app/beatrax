<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\UpdatesTransactionCategory;
use Modules\Ledger\Public\Services\TransactionStatusQuery;

// Defence in depth: the category — when not null — must either belong
// to the user or be a global default-tree row. Without this, the FK
// alone would accept any categories.id from another user, since the FK
// target is the table, not the row's owner.
final readonly class UpdateTransactionCategory implements UpdatesTransactionCategory
{
    public function __construct(private DatabaseManager $db) {}

    public function __invoke(int $transactionId, ?int $categoryId, User $user): int
    {
        $row = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first(['status', 'category_id']);

        if ($row === null || TransactionStatusQuery::locksEdits($row->status)) {
            return 0;
        }

        $currentCategoryId = is_numeric($row->category_id) ? (int) $row->category_id : null;

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

        // Write-only-on-change, like ReassignCounterparty. SQLite reports one
        // affected row for an UPDATE writing the value already there, and every
        // side effect is gated on that count: the memory tally the ranking
        // sorts on, an op every device replays, and a manual provenance stamp.
        if ($currentCategoryId === $categoryId) {
            return 0;
        }

        return Transaction::query()
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->update(['category_id' => $categoryId]);
    }
}
