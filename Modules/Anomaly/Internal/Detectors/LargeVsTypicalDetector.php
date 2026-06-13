<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Detectors;

use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\Support\RobustStatistics;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * Large-vs-typical detector (D-06 / D-07 / D-08).
 *
 * Judges a charge against the user's own per-counterparty history (robust
 * median + k×MAD z-score) over a rolling 12-month window, falling back to
 * the per-category p95 when the merchant has thin history (< 5 prior
 * samples). When both the counterparty and the category are thin the
 * charge cannot be judged and the detector returns null (no fire) rather
 * than guessing.
 *
 * Currency discipline (Pitfall 5 / T-09-07): every amount is read and
 * compared in SETTLED minor units (`settled_amount_minor` /
 * `settled_currency`) so a multi-currency merchant's history stays
 * comparable and a routine USD charge whose settled-EUR amount is typical
 * never produces a spurious flag.
 *
 * Cross-user discipline (T-09-05): every baseline query carries an
 * explicit `where('user_id', ...)` — the BelongsToUser global scope does
 * not fire under queue/console, so the explicit filter is the primary
 * guard.
 *
 * The detector reads `transactions` directly (a sanctioned cross-module
 * read) and never writes it (`noTransactionWritesFromAnomaly` invariant).
 */
final readonly class LargeVsTypicalDetector
{
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

        // D-11: the min-amount floor gates the detector.
        if ($absMinor < $minFloorMinor) {
            return null;
        }

        $direction = self::directionFromType(is_string($txn['type'] ?? null) ? $txn['type'] : 'expense');
        $types = self::typesForDirection($direction);
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

        // Per-category fallback (D-06): only when the category has richer
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
            // Both counterparty and category are thin — cannot be judged.
            return null;
        }

        $absSample = array_map('abs', $categorySample);
        $p95 = RobustStatistics::percentile($absSample, RobustStatistics::CATEGORY_PERCENTILE);
        if ((float) $absMinor > $p95) {
            return [
                'baseline_amount_minor' => (int) round(-$p95),
                'latest_amount_minor' => $settledMinor,
                'currency' => $settledCurrency,
            ];
        }

        return null;
    }

    /**
     * Gathers the prior settled-minor-unit sample for a column key
     * (counterparty_id or category_id), scoped to the user, same direction
     * (via the transaction type set), same settled currency, within the
     * window, excluding the charge under test.
     *
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

    /**
     * Context-derived MAD floor: the larger of the hard minimum and 1% of
     * the sample median magnitude, so a high-value merchant's near-constant
     * history does not trip on a tiny absolute deviation.
     *
     * @param  list<int>  $sample
     */
    private static function madFloorFor(array $sample): int
    {
        $median = RobustStatistics::median(array_map('abs', $sample));

        return (int) max((float) RobustStatistics::MAD_FLOOR_MINOR, $median * 0.01);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toIntOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private static function directionFromType(string $type): string
    {
        return match ($type) {
            'income', 'transfer_in', 'refund' => 'income',
            default => 'expense',
        };
    }

    /**
     * The transaction `type` values that constitute one direction. The
     * baseline must compare like with like: an expense charge against the
     * expense-side history, an inflow against the income-side history.
     *
     * @return list<string>
     */
    private static function typesForDirection(string $direction): array
    {
        return $direction === 'income'
            ? ['income', 'transfer_in', 'refund']
            : ['expense', 'transfer_out', 'fee', 'adjustment'];
    }
}
