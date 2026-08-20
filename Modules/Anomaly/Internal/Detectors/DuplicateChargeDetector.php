<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Detectors;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * @link ../../../../.docs/features/anomaly/detector-maths.md
 */
final readonly class DuplicateChargeDetector
{
    use CoercesScalars;

    public const DUPLICATE_WINDOW_DAYS = 7;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private RecurringSeriesQuery $recurringQuery,
        private BaseCurrency $baseCurrency,
    ) {}

    /**
     * @param  array<string, mixed>  $txn  the raw transactions row under test
     */
    public function fires(array $txn, User $user, int $minFloorMinor): bool
    {
        $settledMinor = self::toInt($txn['settled_amount_minor'] ?? 0);
        $absMinor = abs($settledMinor);
        $counterpartyId = self::toIntOrNull($txn['counterparty_id'] ?? null);

        if ($absMinor < $minFloorMinor || $counterpartyId === null) {
            return false;
        }

        $settledCurrency = is_string($txn['settled_currency'] ?? null) ? $txn['settled_currency'] : $this->baseCurrency->code();
        $direction = Direction::fromTransactionType(is_string($txn['type'] ?? null) ? $txn['type'] : TransactionType::Expense->value)->value;
        $types = Direction::from($direction)->transactionTypes();
        $thisId = self::toInt($txn['id'] ?? 0);
        $postedAt = is_string($txn['posted_at'] ?? null) ? $txn['posted_at'] : $this->clock->now()->toDateString();

        $anchor = CarbonImmutable::parse($postedAt);
        $windowOpen = $anchor->subDays(self::DUPLICATE_WINDOW_DAYS)->toDateString();
        $anchorDate = $anchor->toDateString();

        // Backward-only plus the `id <` tie-break: a genuine double-charge
        // fires exactly once, on the later charge, whichever evaluation path
        // reaches the pair first and in whatever order they were inserted.
        $siblingId = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('counterparty_id', $counterpartyId)
            ->where('settled_amount_minor', $settledMinor)
            ->where('settled_currency', $settledCurrency)
            ->whereIn('type', $types)
            ->whereBetween('posted_at', [$windowOpen, $anchorDate])
            ->where('id', '<', $thisId)
            ->value('id');

        if ($siblingId === null) {
            return false;
        }

        $siblingId = self::toInt($siblingId);

        $membership = $this->recurringQuery->seriesMembershipForTransactionIds([$thisId, $siblingId], $user);
        $bothOnSeries = ($membership[$thisId] ?? false) && ($membership[$siblingId] ?? false);

        return ! $bothOnSeries;
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
