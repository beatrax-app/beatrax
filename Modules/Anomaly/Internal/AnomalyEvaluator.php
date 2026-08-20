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
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Anomaly\Public\Events\AnomalyAlertOpened;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Sync\Public\Events\EntityMutated;

final readonly class AnomalyEvaluator
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
        private LargeVsTypicalDetector $largeDetector,
        private FirstTimeMerchantDetector $firstTimeDetector,
        private DuplicateChargeDetector $duplicateDetector,
        private BaseCurrency $baseCurrency,
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
        $direction = Direction::fromTransactionType(is_string($txn['type'] ?? null) ? $txn['type'] : TransactionType::Expense->value)->value;

        $reasons = [];
        $baselineMinor = null;
        $latestMinor = null;
        $currency = null;

        $largeResult = $this->largeDetector->fires($txn, $user, $sensitivity, $minFloor);
        $largeFromMerchantBaseline = $largeResult !== null;
        if ($largeResult !== null) {
            $reasons[] = 'large';
            $baselineMinor = $largeResult['baseline_amount_minor'];
            $latestMinor = $largeResult['latest_amount_minor'];
            $currency = $largeResult['currency'];
        }

        // A brand-new merchant has no per-merchant baseline for LargeVsTypical
        // to judge, so first-time's overall-spend comparison IS the `large`
        // evidence and injects that reason itself.
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

        // Detection order varies by path; paired devices must store identical
        // `reasons` JSON, so the list is canonicalised before it is encoded.
        $reasons = self::canonicalOrder($reasons);

        $reasons = $this->filterSuppressed($txn, $user, $direction, $reasons, $largeFromMerchantBaseline);
        if ($reasons === []) {
            return;
        }

        $now = $this->clock->now()->toDateTimeString();

        $userId = self::toInt($user->id, 0);
        $row = [
            'user_id' => $user->id,
            'transaction_id' => $transactionId,
            'state' => AnomalyAlertState::Open->value,
            'direction' => $direction,
            'reasons' => json_encode($reasons),
            'baseline_amount_minor' => $baselineMinor,
            'latest_amount_minor' => $latestMinor,
            'currency' => $currency,
            'sensitivity_percent_used' => $sensitivity,
            'detected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Derived, not minted: the detector runs on every paired device, and an
        // autoincrement would give each a different id for one charge's alert.
        // Both halves of the tuple are immutable, so the ids agree and the
        // second device's create collides harmlessly.
        $alertId = DerivedRowId::for('anomaly_alerts', [
            'user_id' => $userId,
            'transaction_id' => $transactionId,
        ]);

        try {
            $this->db->connection()->table('anomaly_alerts')->insert(['id' => $alertId] + $row);

            $this->events->dispatch(new AnomalyAlertOpened(
                userId: $userId,
                anomalyAlertId: $alertId,
                transactionId: $transactionId,
                direction: $direction,
                reasons: $reasons,
                baselineAmountMinor: $baselineMinor,
                latestAmountMinor: $latestMinor,
                currency: $currency,
            ));

            // `$row` omits `id` on purpose: the pk carries it, and this is the
            // exact create-op shape the pairing backfill emits.
            $this->events->dispatch(new EntityMutated(
                table: 'anomaly_alerts',
                pk: $alertId,
                userId: $userId,
                mutationType: 'create',
                dirtyFields: $row,
            ));
        } catch (QueryException) {
            // UNIQUE(transaction_id)/pk collision: re-evaluating the same charge
            // is a silent no-op. This empty catch is the idempotency seam.
        }
    }

    /**
     * @param  array<string, mixed>  $txn
     * @param  list<string>  $reasons
     * @return list<string>
     */
    private function filterSuppressed(array $txn, User $user, string $direction, array $reasons, bool $largeFromMerchantBaseline): array
    {
        $counterpartyId = self::toIntOrNull($txn['counterparty_id'] ?? null);
        $settledMinor = self::toInt($txn['settled_amount_minor'] ?? 0, 0);
        $settledCurrency = is_string($txn['settled_currency'] ?? null) ? $txn['settled_currency'] : $this->baseCurrency->code();

        // A synthetic (first-time-injected) `large` is dropped from the
        // rule-matching set: a per-merchant band must not mute a merchant the
        // user has no history with at all.
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
     * @param  list<string>  $reasons
     * @return list<string>
     */
    private static function canonicalOrder(array $reasons): array
    {
        $order = ['large', 'first_time', 'duplicate'];
        $present = array_unique($reasons);

        return array_values(array_filter($order, static fn (string $r): bool => in_array($r, $present, true)));
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
