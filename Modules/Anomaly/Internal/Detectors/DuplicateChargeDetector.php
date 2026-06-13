<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Detectors;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * Duplicate-charge detector (D-10).
 *
 * Fires when an EARLIER sibling charge exists for the SAME counterparty,
 * the EXACT same settled minor units + settled currency, the same
 * direction, within DUPLICATE_WINDOW_DAYS (7) BACKWARD from the charge
 * under test — the common "double-tap" / accidental re-charge pattern.
 *
 * The window is directional (backward-only, with an `id <` tie-break for
 * same-day siblings) so a genuine double-charge produces exactly ONE alert
 * — on the LATER charge of the pair — regardless of whether the reactive
 * import path, the full-history backfill, or the safety-net sweep is the
 * one evaluating the rows (WR-02). The earlier charge has no backward
 * sibling at its own date, so it never mints a second alert for the same
 * real-world duplicate.
 *
 * Recurring exclusion: when a sibling exists but BOTH the candidate and the
 * sibling are members of an approved recurring series (resolved through
 * Recurring's Public `seriesMembershipForTransactionIds`), the pair is a
 * legit cadence-landed-twice case and the detector does NOT fire. If only
 * one side is on a series, it still fires (a one-off duplicate of a
 * subscription charge is suspicious).
 *
 * The min-amount floor (D-11) gates the detector. Comparison is in settled
 * minor units (Pitfall 5); every query carries an explicit
 * `where('user_id', ...)` (T-09-05). Reads `transactions` directly, never
 * writes it, and consumes Recurring strictly through its Public service
 * surface (RecurringSeriesQuery) — the evaluator never reaches into the
 * Recurring module's internal namespace, models, or tables.
 */
final readonly class DuplicateChargeDetector
{
    /**
     * Duplicate look-back window in days, applied BACKWARD ONLY from the
     * charge under test. 7 days covers the common double-tap / retry /
     * accidental re-charge patterns without catching a legitimate weekly
     * recurrence (those are additionally excluded via recurring-series
     * membership).
     */
    public const DUPLICATE_WINDOW_DAYS = 7;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private RecurringSeriesQuery $recurringQuery,
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
            return false;
        }

        $settledCurrency = is_string($txn['settled_currency'] ?? null) ? $txn['settled_currency'] : 'EUR';
        $direction = self::directionFromType(is_string($txn['type'] ?? null) ? $txn['type'] : 'expense');
        $types = self::typesForDirection($direction);
        $thisId = self::toInt($txn['id'] ?? 0);
        $postedAt = is_string($txn['posted_at'] ?? null) ? $txn['posted_at'] : $this->clock->now()->toDateString();

        $anchor = CarbonImmutable::parse($postedAt);
        $windowOpen = $anchor->subDays(self::DUPLICATE_WINDOW_DAYS)->toDateString();
        $anchorDate = $anchor->toDateString();

        // Find a sibling looking BACKWARD ONLY: same merchant, exact settled
        // amount + currency, same direction, in [anchor-7d, anchor]. The
        // window is directional so a genuine double-charge fires exactly
        // ONCE — on the LATER charge of the pair — across the reactive,
        // backfill, and safety-net-sweep paths (WR-02). The earlier charge
        // has no backward sibling at its own date, so re-evaluating it never
        // mints a second alert. For two charges on the SAME day the `id <
        // $thisId` tie-break keeps the later (higher id) row as the one that
        // fires.
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

        // Recurring exclusion: skip ONLY when BOTH are series members.
        $membership = $this->recurringQuery->seriesMembershipForTransactionIds([$thisId, $siblingId], $user);
        $bothOnSeries = ($membership[$thisId] ?? false) && ($membership[$siblingId] ?? false);

        return ! $bothOnSeries;
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
