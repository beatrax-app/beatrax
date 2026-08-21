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
final readonly class FirstTimeMerchantDetector
{
    use CoercesScalars;

    private const OVERALL_HISTORY_MIN = 3;

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
        $settledCurrency = is_string($txn['settled_currency'] ?? null) ? $txn['settled_currency'] : $this->baseCurrency->code();
        $direction = Direction::fromTransactionType(is_string($txn['type'] ?? null) ? $txn['type'] : TransactionType::Expense->value)->value;
        $types = Direction::from($direction)->transactionTypes();
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
            return false;
        }

        return RobustStatistics::exceedsPercentile($absMinor, $sample, RobustStatistics::CATEGORY_PERCENTILE);
    }
}
