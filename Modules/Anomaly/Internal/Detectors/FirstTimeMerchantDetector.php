<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Detectors;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Anomaly\Internal\Support\ChargeAnchor;
use Modules\Anomaly\Internal\Support\RobustStatistics;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\BaseCurrency;

/**
 * @link ../../../../.docs/features/anomaly/detector-maths.md
 */
final readonly class FirstTimeMerchantDetector
{
    use CoercesScalars;

    private const int OVERALL_HISTORY_MIN = 3;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private BaseCurrency $baseCurrency,
    ) {}

    /**
     * @param  array<string, mixed>  $txn  the raw transactions row under test
     */
    public function fires(array $txn, User $user, int $minFloorMinor): bool
    {
        $settledMinor = self::toInt($txn['settled_amount_minor'] ?? 0);
        $absMinor = abs($settledMinor);

        if ($absMinor < $minFloorMinor) {
            return false;
        }

        $counterpartyId = self::toPositiveIntOrNull($txn['counterparty_id'] ?? null);
        if ($counterpartyId === null) {
            return false;
        }

        $excludeId = self::toInt($txn['id'] ?? 0);

        // One verdict about one row, so both halves read one anchor. Asking
        // "first ever?" of the charge's own date while asking "large?" of
        // today's twelve months is two questions about two different moments.
        $anchor = ChargeAnchor::forRow($txn, $this->clock);

        return $this->isFirstTimeMerchant($user, $counterpartyId, $excludeId, $anchor)
            && $this->isLargeVsOverall($txn, $user, $absMinor, $excludeId, $anchor);
    }

    // PRIOR, not merely other. Asking for no OTHER charge silenced the first
    // charge of every merchant the user went on to use again, which over a
    // backfill is nearly all of them. The same-day `id <` tie-break is the
    // convention DuplicateChargeDetector's backward window already uses.
    private function isFirstTimeMerchant(User $user, int $counterpartyId, int $excludeId, ChargeAnchor $anchor): bool
    {
        $anchorDate = $anchor->date();

        $priorCount = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('counterparty_id', $counterpartyId)
            ->where('id', '!=', $excludeId)
            ->where(function (Builder $backward) use ($anchorDate, $excludeId): void {
                $backward->where('posted_at', '<', $anchorDate)
                    ->orWhere(function (Builder $sameDay) use ($anchorDate, $excludeId): void {
                        $sameDay->where('posted_at', $anchorDate)->where('id', '<', $excludeId);
                    });
            })
            ->count();

        return $priorCount === 0;
    }

    /**
     * @param  array<string, mixed>  $txn
     */
    private function isLargeVsOverall(array $txn, User $user, int $absMinor, int $excludeId, ChargeAnchor $anchor): bool
    {
        $settledCurrency = is_string($txn['settled_currency'] ?? null) ? $txn['settled_currency'] : $this->baseCurrency->forUser($user);
        $types = TransactionType::externalMovementValuesFor(TransactionType::directionOf($txn['type'] ?? null));
        $windowStart = $anchor->baselineWindowStart();

        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('type', $types)
            ->where('settled_currency', $settledCurrency)
            ->where('posted_at', '>=', $windowStart)
            ->where('id', '!=', $excludeId)
            ->pluck('settled_amount_minor');

        $sample = [];
        foreach ($rows as $value) {
            $sample[] = abs(is_numeric($value) ? (int) $value : 0);
        }

        if (count($sample) < self::OVERALL_HISTORY_MIN) {
            return false;
        }

        return RobustStatistics::exceedsPercentile($absMinor, $sample, RobustStatistics::CATEGORY_PERCENTILE);
    }
}
