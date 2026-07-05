<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\ReassignsCounterparty;

/**
 * Mutates `transactions.counterparty_id` for a transaction owned by the
 * given user. Exposed as a Public contract (T-13.4-13b — module boundary
 * fix) so both `TransactionDetail` (manual reassignment) and, in Plan 05,
 * the rule engine, can drive counterparty reassignment without either
 * reaching into Ledger internals or writing raw SQL against another
 * module's table.
 *
 * PURE guarded write — mirrors `UpdateTransactionCategory` exactly: no
 * event dispatch, no provenance stamp. The caller owns emission
 * (`TransactionMutated`) and provenance stamping (`FieldProvenanceWriter`).
 *
 * Guards, in order:
 *   - D-08 reconciled lock: `status === 'reconciled'` → 0, no write.
 *   - Target counterparty ownership (T-13.4-17a): the counterparty must
 *     belong to `$user` → 0 on a cross-user or unknown id, silent no-op.
 *   - Write-only-on-change: the current `counterparty_id` already equals
 *     the target → 0, no redundant write / no spurious event upstream.
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
