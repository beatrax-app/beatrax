<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

/**
 * Minimal Public service returning the current signed balance for an account.
 *
 * Uses sum-of-transactions (the BalanceAnchorResolver fallback path) — sufficient
 * for pot reconciliation headers in v1. Exposed as a Public service so the Pots
 * module never reaches into Forecasting/Internal to call BalanceAnchorResolver
 * directly (module boundary constraint: Internal services are off-limits to other
 * modules).
 *
 * Callers: `PotBalanceQuery::reconciliationForAccount()` (D-15 reconciliation
 * header). The balance this service returns is the same calculation
 * BalanceAnchorResolver falls back to for accounts without a statement-summary
 * anchor.
 *
 * T-03-01 (Information Disclosure mitigation): the explicit `->where('user_id')`
 * guard ensures a foreign account_id returns the caller's own (empty) balance,
 * never another user's transactions.
 */
final class AccountBalanceQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Returns the signed sum of all transaction amounts for $accountId scoped to
     * $user — the same calculation BalanceAnchorResolver falls back to.
     *
     * A positive result means the account has a credit balance. Returns 0 when
     * there are no transactions for this account/user pair.
     *
     * SINGLE-CURRENCY ASSUMPTION (IN-07): the sum has no currency filter and
     * adds raw `amount_minor` across whatever currencies the account's
     * transactions carry. For an account holding more than one transaction
     * currency (e.g. an ICS account with USD Google Play settlements) the
     * result mixes units. This deliberately mirrors the existing
     * BalanceAnchorResolver fallback so the pot reconciliation header and the
     * net-worth figure stay consistent; an FX-aware per-currency balance is a
     * future improvement that must change both call paths together.
     */
    public function currentBalance(int $accountId, User $user): int
    {
        return (int) $this->db->connection()
            ->table('transactions')
            ->where('account_id', $accountId)
            ->where('user_id', $user->id)
            ->sum('amount_minor');
    }

    /**
     * Returns the signed sum of `amount_minor` for $accountId scoped to $user,
     * restricted to rows whose `status` is `cleared` or `reconciled` — i.e.
     * excludes `uncleared` rows (manual cash-book entries not yet confirmed
     * against a bank statement, per D-03/D-09).
     *
     * Mirrors `currentBalance()` column-for-column (`amount_minor`, NOT
     * `settled_amount_minor`) and inherits the same T-03-01 (Information
     * Disclosure) and IN-07 (single-currency) caveats unchanged.
     *
     * T-03-01 (Information Disclosure mitigation): the explicit `->where('user_id')`
     * guard ensures a foreign account_id returns the caller's own (empty) balance,
     * never another user's transactions.
     *
     * SINGLE-CURRENCY ASSUMPTION (IN-07): the sum has no currency filter and
     * adds raw `amount_minor` across whatever currencies the account's
     * transactions carry. For an account holding more than one transaction
     * currency (e.g. an ICS account with USD Google Play settlements) the
     * result mixes units. This deliberately mirrors the existing
     * BalanceAnchorResolver fallback so the pot reconciliation header and the
     * net-worth figure stay consistent; an FX-aware per-currency balance is a
     * future improvement that must change both call paths together.
     */
    public function clearedBalance(int $accountId, User $user): int
    {
        return (int) $this->db->connection()
            ->table('transactions')
            ->where('account_id', $accountId)
            ->where('user_id', $user->id)
            ->whereIn('status', ['cleared', 'reconciled'])
            ->sum('amount_minor');
    }

    /**
     * Date-bounded sibling of `clearedBalance()`: the signed sum of
     * `amount_minor` for `$accountId` scoped to `$user`, restricted to
     * `cleared`/`reconciled` rows posted on or before `$asOf`.
     *
     * This exists so the `/reconcile` surface computes its "matched" check
     * over the SAME window `ReconciliationWriter::completeReconcile()` locks
     * (`posted_at <= $statementDate`, CR-01). Using the un-bounded
     * `clearedBalance()` there would count rows posted after the statement
     * date that the write correctly leaves untouched, producing a spurious
     * discrepancy (or forcing a fabricated target) for any statement dated
     * in the past — the D-06 ASN pre-fill is exactly such a past date.
     *
     * Mirrors `clearedBalance()` column-for-column (`amount_minor`, NOT
     * `settled_amount_minor`) and inherits the same T-03-01 (Information
     * Disclosure) and IN-07 (single-currency) caveats unchanged.
     */
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
