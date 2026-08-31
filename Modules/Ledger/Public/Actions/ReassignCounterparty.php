<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\ReassignsCounterparty;
use Modules\Ledger\Public\Services\TransactionStatusQuery;

final readonly class ReassignCounterparty implements ReassignsCounterparty
{
    public function __construct(private DatabaseManager $db) {}

    public function __invoke(int $transactionId, int $counterpartyId, User $user): int
    {
        $row = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first(['status', 'counterparty_id']);

        // No-op returning 0 for a missing/foreign row or a reconciled one —
        // a reconcile freezes the counterparty along with the rest of the row.
        if ($row === null || TransactionStatusQuery::locksEdits($row->status)) {
            return 0;
        }

        $currentCounterpartyId = is_numeric($row->counterparty_id) ? (int) $row->counterparty_id : null;

        $cpExists = $this->db->connection()
            ->table('counterparties')
            ->where('id', $counterpartyId)
            ->where('user_id', $user->id)
            ->exists();

        // Nothing to write when the target counterparty is already set, or
        // when it does not belong to this user (missing/foreign id).
        if ($currentCounterpartyId === $counterpartyId || ! $cpExists) {
            return 0;
        }

        return Transaction::query()
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->update(['counterparty_id' => $counterpartyId]);
    }
}
