<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Modules\Anomaly\Internal\Detectors\DuplicateChargeDetector;
use Modules\Anomaly\Internal\Detectors\FirstTimeMerchantDetector;
use Modules\Anomaly\Internal\Detectors\LargeVsTypicalDetector;
use Modules\Anomaly\Public\Events\AnomalyAlertOpened;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * Anomaly detection orchestration — the single `evaluate(int
 * $transactionId, User $user)` entry point shared by the reactive job, the
 * full-history backfill, and the scheduled safety-net sweep.
 *
 * The skeleton (idempotent insert, QueryException no-op, event dispatch,
 * cross-module read discipline) is cloned from DriftEvaluator; the math
 * lives in the three injected detectors (LargeVsTypical / FirstTimeMerchant
 * / DuplicateCharge). evaluate():
 *
 *   1. loads the transaction row (reads `transactions` directly — a
 *      sanctioned cross-module read; never writes it);
 *   2. loads the user's anomaly settings (sensitivity + min floor);
 *   3. runs the three detectors, aggregating a reasons[] of tripped keys
 *      ('large' / 'first_time' / 'duplicate') — one alert per transaction,
 *      multi-reason (D-16);
 *   4. drops any reason that a matching anomaly_suppression_rules row
 *      suppresses (checked BEFORE insert, D-17); if no reason survives,
 *      returns without inserting;
 *   5. inserts exactly one anomaly_alerts row carrying reasons[] + the
 *      large-vs-typical baseline trio + sensitivity_percent_used +
 *      direction; a UNIQUE(transaction_id) collision is caught at the
 *      QueryException boundary and treated as a silent no-op;
 *   6. dispatches AnomalyAlertOpened on a successful insert.
 *
 * Cross-user discipline (T-09-05): every raw query carries an explicit
 * `where('user_id', ...)` — the BelongsToUser global scope does not fire
 * under queue/console, where this evaluator runs.
 */
final readonly class AnomalyEvaluator
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
        private LargeVsTypicalDetector $largeDetector,
        private FirstTimeMerchantDetector $firstTimeDetector,
        private DuplicateChargeDetector $duplicateDetector,
    ) {}

    public function evaluate(int $transactionId, User $user): void
    {
        $row = $this->db->connection()->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first();

        if ($row === null) {
            return;
        }

        /** @var array<string, mixed> $txn */
        $txn = (array) $row;

        $sensitivity = self::toInt($user->anomaly_sensitivity_percent, 50);
        $minFloor = self::toInt($user->anomaly_min_amount_minor, 1000);
        $direction = self::directionFromType(is_string($txn['type'] ?? null) ? $txn['type'] : 'expense');

        // Run the three detectors, aggregating a reasons[] (D-16).
        $reasons = [];
        $baselineMinor = null;
        $latestMinor = null;
        $currency = null;

        $largeResult = $this->largeDetector->fires($txn, $user, $sensitivity, $minFloor);
        // CR-02: provenance of the `large` reason. TRUE only when
        // LargeVsTypicalDetector actually tripped (large vs THIS merchant's
        // own history or the category p95). FALSE when `large` is only the
        // synthetic reason first-time injects (large vs the user's OVERALL
        // spend). A per-merchant `large` suppression band must never mute
        // the synthetic, overall-spend `large` of a brand-new merchant.
        $largeFromMerchantBaseline = $largeResult !== null;
        if ($largeResult !== null) {
            $reasons[] = 'large';
            $baselineMinor = $largeResult['baseline_amount_minor'];
            $latestMinor = $largeResult['latest_amount_minor'];
            $currency = $largeResult['currency'];
        }

        // First-time fires ONLY when also large vs overall (D-09). Because
        // that "large vs overall" finding is itself a large signal, a
        // first-time-large charge carries the `large` reason too — a brand
        // new merchant has no per-merchant baseline for LargeVsTypical to
        // judge, so the overall-spend comparison is the large evidence.
        if ($this->firstTimeDetector->fires($txn, $user, $minFloor)) {
            $reasons[] = 'first_time';
            if (! in_array('large', $reasons, true)) {
                $reasons[] = 'large';
            }
        }

        if ($this->duplicateDetector->fires($txn, $user, $minFloor)) {
            $reasons[] = 'duplicate';
        }

        if ($reasons === []) {
            return;
        }

        // Canonicalise the reason order (large, first_time, duplicate) so
        // the stored JSON is stable regardless of detection order.
        $reasons = self::canonicalOrder($reasons);

        // D-17: drop suppressed reasons BEFORE insert. If none survive, no
        // alert is inserted at all. Provenance of `large` is passed so a
        // per-merchant `large` band cannot mute a synthetic, overall-spend
        // `large` injected by first-time (CR-02).
        $reasons = $this->filterSuppressed($txn, $user, $direction, $reasons, $largeFromMerchantBaseline);
        if ($reasons === []) {
            return;
        }

        $now = $this->clock->now()->toDateTimeString();

        try {
            $alertId = $this->db->connection()->table('anomaly_alerts')->insertGetId([
                'user_id' => $user->id,
                'transaction_id' => $transactionId,
                'state' => 'open',
                'direction' => $direction,
                'reasons' => json_encode($reasons),
                'baseline_amount_minor' => $baselineMinor,
                'latest_amount_minor' => $latestMinor,
                'currency' => $currency,
                'sensitivity_percent_used' => $sensitivity,
                'detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException) {
            // UNIQUE(transaction_id) collision — re-evaluation against the
            // same charge is a silent no-op. The idempotency seam is the
            // seam (D-16).
            return;
        }

        $this->events->dispatch(new AnomalyAlertOpened(
            userId: self::toInt($user->id, 0),
            anomalyAlertId: self::toInt($alertId, 0),
            transactionId: $transactionId,
            direction: $direction,
            reasons: $reasons,
            baselineAmountMinor: $baselineMinor,
            latestAmountMinor: $latestMinor,
            currency: $currency,
        ));
    }

    /**
     * Removes any reason for which a matching anomaly_suppression_rules row
     * exists (D-17). A rule matches when the counterparty matches, the
     * detector equals the reason, the direction matches, and the charge's
     * settled amount falls within [band_low, band_high] in the same
     * currency. User-scoped explicitly (T-09-05).
     *
     * CR-02: when the `large` reason is SYNTHETIC (injected by first-time
     * because the charge is large vs OVERALL spend, not large vs this
     * merchant's own baseline), it is excluded from the rule-matching set so
     * a per-merchant `large` suppression band cannot mute it. The
     * per-merchant band describes "this merchant's recurring large charge";
     * a brand-new merchant has no such history, and silently muting its
     * large-amount signal is exactly the fraud-adjacent case the feature
     * exists to surface. (It also preserves the D-09 large+first_time
     * coupling: `large` is never stripped while `first_time` survives.)
     *
     * @param  array<string, mixed>  $txn
     * @param  list<string>  $reasons
     * @return list<string>
     */
    private function filterSuppressed(array $txn, User $user, string $direction, array $reasons, bool $largeFromMerchantBaseline): array
    {
        $counterpartyId = self::toIntOrNull($txn['counterparty_id'] ?? null);
        $settledMinor = self::toInt($txn['settled_amount_minor'] ?? 0, 0);
        $settledCurrency = is_string($txn['settled_currency'] ?? null) ? $txn['settled_currency'] : 'EUR';

        // The detector keys a `large` suppression rule may match against. A
        // synthetic (first-time-injected) `large` is NOT eligible for
        // suppression by a per-merchant `large` band (CR-02).
        $matchableDetectors = $reasons;
        if (! $largeFromMerchantBaseline) {
            $matchableDetectors = array_values(array_filter(
                $matchableDetectors,
                static fn (string $reason): bool => $reason !== 'large',
            ));
        }

        if ($matchableDetectors === []) {
            return $reasons;
        }

        $rules = $this->db->connection()->table('anomaly_suppression_rules')
            ->where('user_id', $user->id)
            ->where('direction', $direction)
            ->where('currency', $settledCurrency)
            ->whereIn('detector', $matchableDetectors)
            ->where('amount_band_low_minor', '<=', $settledMinor)
            ->where('amount_band_high_minor', '>=', $settledMinor)
            ->when($counterpartyId !== null, function (Builder $query) use ($counterpartyId): void {
                $query->where(function (Builder $inner) use ($counterpartyId): void {
                    $inner->where('counterparty_id', $counterpartyId)
                        ->orWhereNull('counterparty_id');
                });
            }, function (Builder $query): void {
                $query->whereNull('counterparty_id');
            })
            ->pluck('detector');

        $suppressed = [];
        foreach ($rules as $detector) {
            if (is_string($detector)) {
                $suppressed[$detector] = true;
            }
        }

        if ($suppressed === []) {
            return $reasons;
        }

        return array_values(array_filter(
            $reasons,
            static fn (string $reason): bool => ! isset($suppressed[$reason]),
        ));
    }

    /**
     * Orders the tripped reasons by the canonical detector order so the
     * stored `reasons` JSON is deterministic.
     *
     * @param  list<string>  $reasons
     * @return list<string>
     */
    private static function canonicalOrder(array $reasons): array
    {
        $order = ['large', 'first_time', 'duplicate'];
        $present = array_unique($reasons);

        return array_values(array_filter($order, static fn (string $r): bool => in_array($r, $present, true)));
    }

    private static function directionFromType(string $type): string
    {
        return match ($type) {
            'income', 'transfer_in', 'refund' => 'income',
            default => 'expense',
        };
    }

    private static function toInt(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
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
