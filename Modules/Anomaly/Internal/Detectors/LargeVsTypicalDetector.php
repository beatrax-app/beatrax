<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Detectors;

use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\Support\RobustStatistics;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Enums\Direction;

/**
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
final readonly class LargeVsTypicalDetector
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $txn  the raw transactions row under test
     * @return array{baseline_amount_minor: int, latest_amount_minor: int, currency: string}|null
     *                                                                                            the explainable trio on a trip, or null when the charge is not large
     */
    public function fires(array $txn, User $user, int $sensitivityPercent, int $minFloorMinor): ?array
    {
        $settledMinor = self::toInt($txn['settled_amount_minor'] ?? 0);
        $settledCurrency = is_string($txn['settled_currency'] ?? null) ? $txn['settled_currency'] : 'EUR';
        $absMinor = abs($settledMinor);

        if ($absMinor < $minFloorMinor) {
            return null;
        }

        $direction = Direction::fromTransactionType(is_string($txn['type'] ?? null) ? $txn['type'] : 'expense')->value;
        $types = Direction::from($direction)->transactionTypes();
        $counterpartyId = self::toIntOrNull($txn['counterparty_id'] ?? null);
        $categoryId = self::toIntOrNull($txn['category_id'] ?? null);
        $windowStart = $this->clock->now()
            ->subMonthsNoOverflow(RobustStatistics::WINDOW_MONTHS)
            ->toDateString();
        $excludeId = self::toInt($txn['id'] ?? 0);

        // Per-counterparty sample in settled minor units, same direction,
        // same settled currency, within the rolling window.
        $counterpartySample = $counterpartyId === null ? [] : $this->sample(
            $user,
            $types,
            $settledCurrency,
            $windowStart,
            $excludeId,
            'counterparty_id',
            $counterpartyId,
        );

        if (count($counterpartySample) >= RobustStatistics::THIN_HISTORY_CUTOFF) {
            $z = RobustStatistics::robustZ($absMinor, $counterpartySample, self::madFloorFor($counterpartySample));
            if ($z > RobustStatistics::kForSensitivity($sensitivityPercent)) {
                return [
                    'baseline_amount_minor' => (int) round(-RobustStatistics::median(array_map('abs', $counterpartySample))),
                    'latest_amount_minor' => $settledMinor,
                    'currency' => $settledCurrency,
                ];
            }

            return null;
        }

        // Per-category fallback: only when the category has richer
        // history. Trip on x > p95(category, same direction, same window).
        if ($categoryId === null) {
            return null;
        }

        $categorySample = $this->sample(
            $user,
            $types,
            $settledCurrency,
            $windowStart,
            $excludeId,
            'category_id',
            $categoryId,
        );

        if (count($categorySample) < RobustStatistics::THIN_HISTORY_CUTOFF) {
            return null;
        }

        $absSample = array_map('abs', $categorySample);
        $p95 = RobustStatistics::percentile($absSample, RobustStatistics::CATEGORY_PERCENTILE);
        // Tie-inclusive boundary: a charge EQUAL to the category p95 fires,
        // so a repeat of the largest-ever charge is not a silent false
        // negative. {@see RobustStatistics::exceedsPercentile}
        if (RobustStatistics::exceedsPercentile($absMinor, $categorySample, RobustStatistics::CATEGORY_PERCENTILE)) {
            return [
                'baseline_amount_minor' => (int) round(-$p95),
                'latest_amount_minor' => $settledMinor,
                'currency' => $settledCurrency,
            ];
        }

        return null;
    }

    /**
     * @param  list<string>  $types
     * @return list<int>
     */
    private function sample(
        User $user,
        array $types,
        string $settledCurrency,
        string $windowStart,
        int $excludeId,
        string $column,
        int $columnValue,
    ): array {
        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where($column, $columnValue)
            ->whereIn('type', $types)
            ->where('settled_currency', $settledCurrency)
            ->where('posted_at', '>=', $windowStart)
            ->where('id', '!=', $excludeId)
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
