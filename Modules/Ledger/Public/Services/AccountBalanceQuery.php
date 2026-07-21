<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
final class AccountBalanceQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    // A positive result means a credit balance; 0 when there are no
    // transactions for this account/user pair. See the linked
    // architecture page for the shared single-currency caveat.
    public function currentBalance(int $accountId, User $user): int
    {
        return (int) $this->db->connection()
            ->table('transactions')
            ->where('account_id', $accountId)
            ->where('user_id', $user->id)
            ->sum('amount_minor');
    }

    // Excludes uncleared rows (manual cash-book entries not yet
    // confirmed against a bank statement). See the linked architecture
    // page for the shared caveats.
    public function clearedBalance(int $accountId, User $user): int
    {
        return (int) $this->db->connection()
            ->table('transactions')
            ->where('account_id', $accountId)
            ->where('user_id', $user->id)
            ->whereIn('status', ['cleared', 'reconciled'])
            ->sum('amount_minor');
    }

    // Date-bounded sibling of clearedBalance(): lets /reconcile compute
    // its "matched" check over the same posted_at <= $asOf window
    // ReconciliationWriter::completeReconcile() locks, so it never
    // counts rows the write correctly leaves untouched.
    public function clearedBalanceAsOf(int $accountId, User $user, CarbonImmutable $asOf): int
    {
        return (int) $this->db->connection()
            ->table('transactions')
            ->where('account_id', $accountId)
            ->where('user_id', $user->id)
            ->whereIn('status', ['cleared', 'reconciled'])
            ->where('posted_at', '<=', $asOf->toDateString())
            ->sum('amount_minor');
    }
}
