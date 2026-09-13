<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Detectors;

use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\Support\BackwardOnly;
use Modules\Anomaly\Internal\Support\ChargeAnchor;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Support\NewestTransactionFirst;
use Modules\Recurring\Public\Services\TransactionSeriesMembershipQuery;

/**
 * @link ../../../../.docs/features/anomaly/detector-maths.md
 */
final readonly class DuplicateChargeDetector
{
    use CoercesScalars;

    public const int DUPLICATE_WINDOW_DAYS = 7;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private TransactionSeriesMembershipQuery $seriesMembership,
        private BaseCurrency $baseCurrency,
    ) {}

    /**
     * @param  array<string, mixed>  $txn  the raw transactions row under test
     */
    public function fires(array $txn, User $user, int $minFloorMinor): bool
    {
        $settledMinor = self::toInt($txn['settled_amount_minor'] ?? 0);
        $absMinor = abs($settledMinor);
        $counterpartyId = self::toPositiveIntOrNull($txn['counterparty_id'] ?? null);

        if ($absMinor < $minFloorMinor || $counterpartyId === null) {
            return false;
        }

        $settledCurrency = is_string($txn['settled_currency'] ?? null) ? $txn['settled_currency'] : $this->baseCurrency->forUser($user);
        $types = TransactionType::externalMovementValuesFor(TransactionType::directionOf($txn['type'] ?? null));
        $thisId = self::toInt($txn['id'] ?? 0);

        $anchor = ChargeAnchor::forRow($txn, $this->clock);
        $windowOpen = $anchor->daysBefore(self::DUPLICATE_WINDOW_DAYS);
        $anchorDate = $anchor->date();

        // Backward-only over a key both devices compute, so a genuine double
        // charge fires once and on the same capture everywhere. An `id <` here
        // decided which rows were CANDIDATES, not just their order, and two
        // devices alerted on different captures of one group.
        $siblingId = $this->db->connection()->table('transactions')
            ->join(
                'accounts as '.NewestTransactionFirst::ACCOUNT,
                NewestTransactionFirst::ACCOUNT.'.id',
                '=',
                'transactions.account_id',
            )
            ->where('transactions.user_id', $user->id)
            ->where('transactions.counterparty_id', $counterpartyId)
            ->where('transactions.settled_amount_minor', $settledMinor)
            ->where('transactions.settled_currency', $settledCurrency)
            ->whereIn('transactions.type', $types)
            // The window as a closed range on its own, beside the exact
            // comparison: a bare `(a, b, …) < (…)` leaves the planner no range
            // to seek on, and this read walks one merchant's whole history.
            ->where('transactions.posted_at', '>=', $windowOpen)
            ->where('transactions.posted_at', '<=', $anchorDate)
            ->whereRaw(BackwardOnly::COMPARISON, BackwardOnly::anchorKey($txn))
            // The nearest prior sibling under that same key, so the membership
            // test below reads one named row and the peer names the same one.
            ->orderByRaw(NewestTransactionFirst::ACROSS_ACCOUNTS)
            ->value('transactions.id');

        if ($siblingId === null) {
            return false;
        }

        $siblingId = self::toInt($siblingId);

        $membership = $this->seriesMembership->seriesMembershipForTransactionIds([$thisId, $siblingId], $user);
        $bothOnSeries = ($membership[$thisId] ?? false) && ($membership[$siblingId] ?? false);

        return ! $bothOnSeries;
    }
}
