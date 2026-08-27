<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\UpdatesTransactionCategory;
use Modules\Ledger\Public\Enums\ClearedStatus;

// Defence in depth: the category — when not null — must either belong
// to the user or be a global default-tree row. Without this, the FK
// alone would accept any categories.id from another user, since the FK
// target is the table, not the row's owner.
final class UpdateTransactionCategory implements UpdatesTransactionCategory
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function __invoke(int $transactionId, ?int $categoryId, User $user): int
    {
        $row = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first(['status', 'category_id']);

        if ($row === null || $row->status === ClearedStatus::Reconciled->value) {
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
        // affected row for an UPDATE that writes the value already there, and
        // AssignCategory gates every side effect on that count: re-picking the
        // category a row already shows bumped merchant_memories.occurrence_count
        // (the very number the memory ranking sorts on), wrote an op every
        // device replays, and stamped field_provenance.category_id = 'manual',
        // which locks the field out of every future rule re-apply.
        if ($currentCategoryId === $categoryId) {
            return 0;
        }

        return Transaction::query()
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->update(['category_id' => $categoryId]);
    }
}
