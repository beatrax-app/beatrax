<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\ClearedStatus;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#accountbalancequery--caveats-shared-by-all-four-methods
 */
final class AccountBalanceQuery
{
    /** @var list<string> */
    private const array CLEARED_STATUSES = [ClearedStatus::Cleared->value, ClearedStatus::Reconciled->value];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AccountStartingBalanceQuery $startingBalances,
    ) {}

    public function currentBalance(int $accountId, User $user): int
    {
        return $this->sumFromBaseline($accountId, $user, null, null);
    }

    public function clearedBalance(int $accountId, User $user): int
    {
        return $this->sumFromBaseline($accountId, $user, self::CLEARED_STATUSES, null);
    }

    // What the account actually holds on a given day. Net worth asks this and
    // not currentBalance(), which counts a future-dated row as money already
    // in hand, nor the forecast anchor, which answers where a projection
    // starts rather than where the account stands.
    public function currentBalanceAsOf(int $accountId, User $user, CarbonImmutable $asOf): int
    {
        return $this->sumFromBaseline($accountId, $user, null, $asOf);
    }

    // /reconcile checks "matched" over the same posted_at <= $asOf window
    // ReconciliationWriter::completeReconcile() locks, so it never counts
    // rows the write correctly leaves untouched.
    public function clearedBalanceAsOf(int $accountId, User $user, CarbonImmutable $asOf): int
    {
        return $this->sumFromBaseline($accountId, $user, self::CLEARED_STATUSES, $asOf);
    }

    /**
     * @param  list<string>|null  $statuses
     */
    private function sumFromBaseline(int $accountId, User $user, ?array $statuses, ?CarbonImmutable $asOf): int
    {
        $baseline = $this->startingBalances->forAccount($accountId, $user);

        $query = $this->db->connection()
            ->table('transactions')
            ->where('account_id', $accountId)
            ->where('user_id', $user->id);

        if ($statuses !== null) {
            $query->whereIn('status', $statuses);
        }

        if ($asOf !== null) {
            $query->where('posted_at', '<=', $asOf->toDateString());
        }

        // The baseline is the position BEFORE its own day's rows, so a row
        // posted exactly on that date lands on top of it rather than inside it.
        if ($baseline['date'] !== null) {
            $query->where('posted_at', '>=', $baseline['date']->toDateString());
        }

        // settled_amount_minor, not amount_minor: the settled pair is the row
        // as the ACCOUNT holds it, so a USD purchase on a euro account adds
        // euro cents. Summing the native amount added the dollar figure
        // straight into the euro total.
        return $baseline['minorUnits'] + (int) $query->sum('settled_amount_minor');
    }
}
