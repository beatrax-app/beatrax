<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Detectors;

use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\Support\RobustStatistics;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\BaseCurrency;

/**
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
final readonly class LargeVsTypicalDetector
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private BaseCurrency $baseCurrency,
    ) {}

    /**
     * @param  array<string, mixed>  $txn  the raw transactions row under test
     * @return array{baseline_amount_minor: int, latest_amount_minor: int, currency: string}|null
     *                                                                                            the explainable trio on a trip, or null when the charge is not large
     */
    public function fires(array $txn, User $user, int $sensitivityPercent, int $minFloorMinor): ?array
    {
        $settledMinor = self::toInt($txn['settled_amount_minor'] ?? 0);
        $settledCurrency = is_string($txn['settled_currency'] ?? null) ? $txn['settled_currency'] : $this->baseCurrency->code();
        $absMinor = abs($settledMinor);

        if ($absMinor < $minFloorMinor) {
            return null;
        }

        $direction = Direction::fromTransactionType(is_string($txn['type'] ?? null) ? $txn['type'] : TransactionType::Expense->value)->value;
        $counterpartyId = self::toIntOrNull($txn['counterparty_id'] ?? null);
        $categoryId = self::toIntOrNull($txn['category_id'] ?? null);

        $context = new LargeSampleContext(
            user: $user,
            types: Direction::from($direction)->transactionTypes(),
            currency: $settledCurrency,
            windowStart: $this->clock->now()->subMonthsNoOverflow(RobustStatistics::WINDOW_MONTHS)->toDateString(),
            excludeId: self::toInt($txn['id'] ?? 0),
        );

        // Per-counterparty sample in settled minor units, same direction,
        // same settled currency, within the rolling window.
        $counterpartySample = $counterpartyId === null ? [] : $this->sample($context, 'counterparty_id', $counterpartyId);

        if (count($counterpartySample) >= RobustStatistics::THIN_HISTORY_CUTOFF) {
            return self::counterpartyTrip($absMinor, $settledMinor, $settledCurrency, $counterpartySample, $sensitivityPercent);
        }

        return $this->categoryTrip($absMinor, $settledMinor, $context, $categoryId);
    }

    /**
     * @param  list<int>  $sample
     * @return array{baseline_amount_minor: int, latest_amount_minor: int, currency: string}|null
     */
    private static function counterpartyTrip(int $absMinor, int $settledMinor, string $settledCurrency, array $sample, int $sensitivityPercent): ?array
    {
        $z = RobustStatistics::robustZ($absMinor, $sample, self::madFloorFor($sample));
        if ($z <= RobustStatistics::kForSensitivity($sensitivityPercent)) {
            return null;
        }

        return [
            'baseline_amount_minor' => (int) round(-RobustStatistics::median(array_map('abs', $sample))),
            'latest_amount_minor' => $settledMinor,
            'currency' => $settledCurrency,
        ];
    }

    // Per-category fallback: consulted only when the counterparty history is
    // too thin, and it trips on a charge above the category p95 for the same
    // direction and window.
    /**
     * @return array{baseline_amount_minor: int, latest_amount_minor: int, currency: string}|null
     */
    private function categoryTrip(int $absMinor, int $settledMinor, LargeSampleContext $context, ?int $categoryId): ?array
    {
        if ($categoryId === null) {
            return null;
        }

        $categorySample = $this->sample($context, 'category_id', $categoryId);

        // Tie-inclusive boundary via exceedsPercentile: a charge EQUAL to the
        // category p95 fires, so a repeat of the largest-ever charge is not a
        // silent false negative.
        if (count($categorySample) < RobustStatistics::THIN_HISTORY_CUTOFF
            || ! RobustStatistics::exceedsPercentile($absMinor, $categorySample, RobustStatistics::CATEGORY_PERCENTILE)) {
            return null;
        }

        $p95 = RobustStatistics::percentile(array_map('abs', $categorySample), RobustStatistics::CATEGORY_PERCENTILE);

        return [
            'baseline_amount_minor' => (int) round(-$p95),
            'latest_amount_minor' => $settledMinor,
            'currency' => $context->currency,
        ];
    }

    /**
     * @return list<int>
     */
    private function sample(LargeSampleContext $context, string $column, int $columnValue): array
    {
        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $context->user->id)
            ->where($column, $columnValue)
            ->whereIn('type', $context->types)
            ->where('settled_currency', $context->currency)
            ->where('posted_at', '>=', $context->windowStart)
            ->where('id', '!=', $context->excludeId)
            ->pluck('settled_amount_minor');

        $sample = [];
        foreach ($rows as $value) {
            $sample[] = is_numeric($value) ? (int) $value : 0;
        }

        return $sample;
    }

    // The larger of the hard MAD_FLOOR_MINOR and 1% of the sample median
    // magnitude, so a high-value merchant's near-constant history does not
    // trip on a tiny absolute deviation; cheap merchants get the flat
    // floor, larger merchants get a value-scaled one.
    /**
     * @param  list<int>  $sample
     */
    private static function madFloorFor(array $sample): int
    {
        $median = RobustStatistics::median(array_map('abs', $sample));

        $floor = max((float) RobustStatistics::MAD_FLOOR_MINOR, $median * 0.01);

        return (int) $floor;
    }

    private static function toIntOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
