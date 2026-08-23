<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\ValueObjects\AccountBalance;
use stdClass;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#accountbalancequery--caveats-shared-by-all-four-methods
 */
final class AccountBalanceQuery
{
    use CoercesScalars;

    /** @var list<string> */
    private const array CLEARED_STATUSES = [ClearedStatus::Cleared->value, ClearedStatus::Reconciled->value];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AccountStartingBalanceQuery $startingBalances,
    ) {}

    public function currentBalance(int $accountId, User $user): AccountBalance
    {
        return $this->sumFromBaseline($accountId, $user, null, null);
    }

    public function clearedBalance(int $accountId, User $user): AccountBalance
    {
        return $this->sumFromBaseline($accountId, $user, self::CLEARED_STATUSES, null);
    }

    // What the account actually holds on a given day. Net worth asks this and
    // not currentBalance(), which counts a future-dated row as money already
    // in hand, nor the forecast anchor, which answers where a projection
    // starts rather than where the account stands.
    public function currentBalanceAsOf(int $accountId, User $user, CarbonImmutable $asOf): AccountBalance
    {
        return $this->sumFromBaseline($accountId, $user, null, $asOf);
    }

    // /reconcile checks "matched" over the same posted_at <= $asOf window
    // ReconciliationWriter::completeReconcile() locks, so it never counts
    // rows the write correctly leaves untouched.
    public function clearedBalanceAsOf(int $accountId, User $user, CarbonImmutable $asOf): AccountBalance
    {
        return $this->sumFromBaseline($accountId, $user, self::CLEARED_STATUSES, $asOf);
    }

    /**
     * @param  list<string>|null  $statuses
     */
    private function sumFromBaseline(int $accountId, User $user, ?array $statuses, ?CarbonImmutable $asOf): AccountBalance
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
        // Bounded on the baseline's OWN currency only: it says what the account
        // held in that one, and a row in another has no baseline covering it.
        if ($baseline['date'] !== null && $baseline['currency'] !== '') {
            $query->where(static function (Builder $unbaselined) use ($baseline): void {
                $unbaselined->where('settled_currency', '!=', $baseline['currency'])
                    ->orWhere('posted_at', '>=', $baseline['date']->toDateString());
            });
        }

        // The baseline opens the account's own default_currency line even at
        // zero, so an account with no rows yet still reports the currency it
        // is denominated in rather than nothing at all.
        $byCurrency = $baseline['currency'] === '' ? [] : [$baseline['currency'] => $baseline['minorUnits']];

        // settled_amount_minor grouped by settled_currency, never summed
        // across it: the settled pair is the row as the ACCOUNT holds it, and
        // a Revolut account holds euro and dollar rows side by side. One total
        // over both added euro cents to dollar cents and called it net worth.
        $rows = $query
            ->groupBy('settled_currency')
            ->selectRaw('settled_currency, sum(settled_amount_minor) as sum_minor')
            ->get();

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $currency = self::toString($row->settled_currency);
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + self::toInt($row->sum_minor);
        }

        return AccountBalance::of($byCurrency);
    }
}
