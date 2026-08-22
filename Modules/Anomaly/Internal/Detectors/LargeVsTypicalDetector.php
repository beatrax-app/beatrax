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
 * @link ../../../../.docs/features/anomaly/detector-maths.md
 */
final readonly class LargeVsTypicalDetector
{
    use CoercesScalars;

    private const float MAD_FLOOR_MEDIAN_FRACTION = 0.01;

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

        $direction = TransactionType::directionOf($txn['type'] ?? null)->value;
        $counterpartyId = self::toPositiveIntOrNull($txn['counterparty_id'] ?? null);
        $categoryId = self::toPositiveIntOrNull($txn['category_id'] ?? null);

        $context = new LargeSampleContext(
            user: $user,
            types: TransactionType::valuesFor(Direction::from($direction)),
            currency: $settledCurrency,
            windowStart: $this->clock->now()->subMonthsNoOverflow(RobustStatistics::WINDOW_MONTHS)->toDateString(),
            excludeId: self::toInt($txn['id'] ?? 0),
        );

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

    /**
     * @return array{baseline_amount_minor: int, latest_amount_minor: int, currency: string}|null
     */
    private function categoryTrip(int $absMinor, int $settledMinor, LargeSampleContext $context, ?int $categoryId): ?array
    {
        if ($categoryId === null) {
            return null;
        }

        $categorySample = $this->sample($context, 'category_id', $categoryId);

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

    /**
     * @param  list<int>  $sample
     */
    private static function madFloorFor(array $sample): int
    {
        $median = RobustStatistics::median(array_map('abs', $sample));

        $floor = max((float) RobustStatistics::MAD_FLOOR_MINOR, $median * self::MAD_FLOOR_MEDIAN_FRACTION);

        return (int) $floor;
    }
}
