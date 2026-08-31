<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Detectors;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Anomaly\Internal\Support\ChargeAnchor;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\BaseCurrency;
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

        // Backward-only: a genuine double-charge fires exactly once, on the
        // later-DATED charge. The `id <` tie-break settles a same-day pair
        // only — applied to every row it hid an earlier sibling whose id
        // happened to be higher, which is every newest-first CSV export.
        $siblingId = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('counterparty_id', $counterpartyId)
            ->where('settled_amount_minor', $settledMinor)
            ->where('settled_currency', $settledCurrency)
            ->whereIn('type', $types)
            ->where('posted_at', '>=', $windowOpen)
            ->where(function (Builder $backward) use ($anchorDate, $thisId): void {
                $backward->where('posted_at', '<', $anchorDate)
                    ->orWhere(function (Builder $sameDay) use ($anchorDate, $thisId): void {
                        $sameDay->where('posted_at', $anchorDate)->where('id', '<', $thisId);
                    });
            })
            // The nearest prior sibling, so the membership test below reads one
            // named row rather than whichever the scan happened to reach first.
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->value('id');

        if ($siblingId === null) {
            return false;
        }

        $siblingId = self::toInt($siblingId);

        $membership = $this->seriesMembership->seriesMembershipForTransactionIds([$thisId, $siblingId], $user);
        $bothOnSeries = ($membership[$thisId] ?? false) && ($membership[$siblingId] ?? false);

        return ! $bothOnSeries;
    }
}
