<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Detectors;

use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\Support\RobustStatistics;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * First-time-merchant detector (D-09).
 *
 * Fires ONLY when BOTH hold:
 *   1. first-time — the user has no prior `transactions` row for this
 *      counterparty (same user); AND
 *   2. large vs overall — the charge's settled amount exceeds a robust
 *      threshold over the user's OVERALL same-direction settled-currency
 *      distribution within the rolling window.
 *
 * The "large AND first-time" coupling (D-09) is the volume control: a new
 * payee with a small/typical charge is noise, not signal, so it must not
 * fire. The min-amount floor (D-11) gates the detector underneath.
 *
 * Currency + cross-user discipline mirror LargeVsTypicalDetector: settled
 * minor units only (Pitfall 5), explicit `where('user_id', ...)` on every
 * query (T-09-05). Reads `transactions` directly, never writes it.
 */
final readonly class FirstTimeMerchantDetector
{
    /**
     * Minimum overall same-direction sample size before "large vs overall"
     * can be judged. Lower than the per-merchant THIN_HISTORY_CUTOFF: the
     * overall distribution is the user's whole spend, so a handful of
     * points already establishes a typical band. Below this we abstain
     * (a brand-new ledger has no baseline to call anything "large").
     */
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

        // D-11: the min-amount floor gates the detector.
        if ($absMinor < $minFloorMinor) {
            return false;
        }

        $counterpartyId = self::toIntOrNull($txn['counterparty_id'] ?? null);
        if ($counterpartyId === null) {
            // No resolved merchant identity — cannot judge "first-time".
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

        // WR-04: tie-inclusive boundary — a first charge whose magnitude
        // EQUALS the overall p95 fires (the percentile collapses toward the
        // sample max for thin overall history). See
        // RobustStatistics::exceedsPercentile.
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
