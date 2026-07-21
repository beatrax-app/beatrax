<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\ReassignsCounterparty;

/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
final class ReassignCounterparty implements ReassignsCounterparty
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function __invoke(int $transactionId, int $counterpartyId, User $user): int
    {
        $row = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first(['status', 'counterparty_id']);

        if ($row === null) {
            return 0;
        }

        if ($row->status === 'reconciled') {
            return 0;
        }

        $cpExists = $this->db->connection()
            ->table('counterparties')
            ->where('id', $counterpartyId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $cpExists) {
            return 0;
        }

        $currentCounterpartyId = is_numeric($row->counterparty_id) ? (int) $row->counterparty_id : null;
        if ($currentCounterpartyId === $counterpartyId) {
            return 0;
        }

        return Transaction::query()
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->update(['counterparty_id' => $counterpartyId]);
    }
}
