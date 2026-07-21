<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Detectors;

use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\Support\RobustStatistics;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
final readonly class FirstTimeMerchantDetector
{
    // Lower than the per-merchant thin-history cutoff: the overall
    // distribution is the user's whole spend, so a handful of points
    // already establishes a typical band. Below this the detector abstains.
    private const OVERALL_HISTORY_MIN = 3;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
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

        $counterpartyId = self::toIntOrNull($txn['counterparty_id'] ?? null);
        if ($counterpartyId === null) {
            return false;
        }

        $excludeId = self::toInt($txn['id'] ?? 0);

        return $this->isFirstTimeMerchant($user, $counterpartyId, $excludeId)
            && $this->isLargeVsOverall($txn, $user, $absMinor, $excludeId);
    }

    private function isFirstTimeMerchant(User $user, int $counterpartyId, int $excludeId): bool
    {
        $priorCount = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('counterparty_id', $counterpartyId)
            ->where('id', '!=', $excludeId)
            ->count();

        return $priorCount === 0;
    }

    /**
     * @param  array<string, mixed>  $txn
     */
    private function isLargeVsOverall(array $txn, User $user, int $absMinor, int $excludeId): bool
    {
        $settledCurrency = is_string($txn['settled_currency'] ?? null) ? $txn['settled_currency'] : 'EUR';
        $direction = self::directionFromType(is_string($txn['type'] ?? null) ? $txn['type'] : 'expense');
        $types = self::typesForDirection($direction);
        $windowStart = $this->clock->now()
            ->subMonthsNoOverflow(RobustStatistics::WINDOW_MONTHS)
            ->toDateString();

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
            // Not enough overall history to call anything "large vs
            // overall" — abstain rather than guess.
            return false;
        }

        // Tie-inclusive boundary: a charge whose magnitude EQUALS the
        // overall p95 fires (the percentile collapses toward the sample
        // max for thin overall history). {@see RobustStatistics::exceedsPercentile}
        return RobustStatistics::exceedsPercentile($absMinor, $sample, RobustStatistics::CATEGORY_PERCENTILE);
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
     * @return list<string>
     */
    private static function typesForDirection(string $direction): array
    {
        return $direction === 'income'
            ? ['income', 'transfer_in', 'refund']
            : ['expense', 'transfer_out', 'fee', 'adjustment'];
    }
}
