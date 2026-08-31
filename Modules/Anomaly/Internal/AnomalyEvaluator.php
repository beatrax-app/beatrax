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
use Modules\Anomaly\Internal\Enums\AnomalyDetector;
use Modules\Anomaly\Internal\Support\AnomalySensitivity;
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Anomaly\Public\Events\AnomalyAlertOpened;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\QueryFailure;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Sync\Public\Events\EntityMutated;

final readonly class AnomalyEvaluator
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
        private LargeVsTypicalDetector $largeDetector,
        private FirstTimeMerchantDetector $firstTimeDetector,
        private DuplicateChargeDetector $duplicateDetector,
        private BaseCurrency $baseCurrency,
        private CrossCurrencyTotal $fx,
    ) {}

    // An amount in the reader's currency, not a count of minor units: a yen
    // has none at all, so a JPY1,200 charge worth EUR7.55 carried the integer
    // 1200 past a floor meaning EUR10.00. Unfloored where no rate reaches,
    // never floored by a number meaning nothing in that currency.
    private function floorIn(User $user, string $currency): int
    {
        $readerMinor = self::toInt($user->anomaly_min_amount_minor, AnomalySensitivity::DEFAULT_MIN_AMOUNT_MINOR);
        $readerCurrency = $this->baseCurrency->forUser($user);

        if ($currency === $readerCurrency) {
            return $readerMinor;
        }

        $floor = Money::tryOfMinor($readerMinor, $readerCurrency);
        $converted = $floor === null
            ? null
            : $this->fx->convert($floor, $currency, $this->fx->ratesTo([$readerCurrency], $currency));

        return $converted?->toMinor() ?? 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowToJudge(int $transactionId, User $user): ?array
    {
        $row = $this->db->connection()->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $txn */
        $txn = (array) $row;

        // The whole module asks "did the reader do something unusual", and
        // moving your own money between your own accounts is not something you
        // did TO anyone. Decided here rather than in each detector because this
        // is eligibility for the row, not the maths of one reason.
        return TransactionType::isExternalMovementOf($txn['type'] ?? null) ? $txn : null;
    }

    public function evaluate(int $transactionId, User $user): void
    {
        $txn = $this->rowToJudge($transactionId, $user);

        if ($txn === null) {
            return;
        }

        $sensitivity = AnomalySensitivity::fromStored($user->anomaly_sensitivity_percent);
        $direction = TransactionType::directionOf($txn['type'] ?? null)->value;

        $reasons = [];
        // The charge's own amount is a fact every alert knows, so it is stamped
        // whichever detector fires. Left to the large path alone, a duplicate-
        // or first-time-only alert carried nulls and the row read them back to
        // the reader as a real EUR 0.00 -> EUR 0.00 movement.
        $baselineMinor = null;
        $latestMinor = self::toInt($txn['settled_amount_minor'] ?? 0, 0);
        $currency = is_string($txn['settled_currency'] ?? null) && $txn['settled_currency'] !== ''
            ? $txn['settled_currency']
            : $this->baseCurrency->forUser($user);

        // Every detector compares against the row's own settled minor units, so
        // the floor is restated in that currency once here rather than three
        // times below.
        $minFloor = $this->floorIn($user, $currency);

        $largeResult = $this->largeDetector->fires($txn, $user, $sensitivity, $minFloor);
        $largeFromMerchantBaseline = $largeResult !== null;
        if ($largeResult !== null) {
            $reasons[] = AnomalyDetector::Large;
            $baselineMinor = $largeResult['baseline_amount_minor'];
            $latestMinor = $largeResult['latest_amount_minor'];
            $currency = $largeResult['currency'];
        }

        // A brand-new merchant has no per-merchant baseline for LargeVsTypical
        // to judge, so first-time's overall-spend comparison IS the `large`
        // evidence and injects that reason itself.
        if ($this->firstTimeDetector->fires($txn, $user, $minFloor)) {
            $reasons[] = AnomalyDetector::FirstTime;
            if (! in_array(AnomalyDetector::Large, $reasons, true)) {
                $reasons[] = AnomalyDetector::Large;
            }
        }

        if ($this->duplicateDetector->fires($txn, $user, $minFloor)) {
            $reasons[] = AnomalyDetector::Duplicate;
        }

        if ($reasons !== []) {
            // Detection order varies by path; paired devices must store identical
            // `reasons` JSON, so the list is canonicalised before it is encoded.
            $reasons = AnomalyDetector::inCanonicalOrder($reasons);
            $reasons = $this->filterSuppressed($txn, $user, $direction, $reasons, $largeFromMerchantBaseline);
        }

        // Nothing fired, or everything that fired was suppressed: either way
        // there is no alert to open.
        if ($reasons === []) {
            return;
        }

        $reasonValues = array_map(static fn (AnomalyDetector $d): string => $d->value, $reasons);

        $now = $this->clock->now()->toDateTimeString();

        $userId = self::toInt($user->id, 0);
        $row = [
            'user_id' => $user->id,
            'transaction_id' => $transactionId,
            'state' => AnomalyAlertState::Open->value,
            'direction' => $direction,
            'reasons' => json_encode($reasonValues),
            'baseline_amount_minor' => $baselineMinor,
            'latest_amount_minor' => $latestMinor,
            'currency' => $currency,
            'sensitivity_percent_used' => $sensitivity->percent,
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
        } catch (QueryException $e) {
            // Only the UNIQUE(transaction_id) collision is the idempotency
            // seam; a foreign key or a full disk producing the same silent
            // no-op is indistinguishable from "already evaluated". A RAISE
            // trigger reports 23000 too, so the row itself is the proof.
            if (! QueryFailure::isUniqueViolation($e) || ! $this->alertExistsFor($transactionId, $userId)) {
                throw $e;
            }

            return;
        }

        // Outside the insert guard: a listener that throws must not cost the
        // capture event, which is what carries the row to the paired device.
        $this->events->dispatch(new AnomalyAlertOpened(
            userId: $userId,
            anomalyAlertId: $alertId,
            transactionId: $transactionId,
            direction: $direction,
            reasons: $reasonValues,
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
    }

    private function alertExistsFor(int $transactionId, int $userId): bool
    {
        return $this->db->connection()->table('anomaly_alerts')
            ->where('user_id', $userId)
            ->where('transaction_id', $transactionId)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $txn
     * @param  list<AnomalyDetector>  $reasons
     * @return list<AnomalyDetector>
     */
    private function filterSuppressed(array $txn, User $user, string $direction, array $reasons, bool $largeFromMerchantBaseline): array
    {
        $counterpartyId = self::toPositiveIntOrNull($txn['counterparty_id'] ?? null);
        $settledMinor = self::toInt($txn['settled_amount_minor'] ?? 0, 0);
        $settledCurrency = is_string($txn['settled_currency'] ?? null) ? $txn['settled_currency'] : $this->baseCurrency->forUser($user);

        // A synthetic (first-time-injected) `large` is dropped from the
        // rule-matching set: a per-merchant band must not mute a merchant the
        // user has no history with at all.
        $matchableDetectors = $reasons;
        if (! $largeFromMerchantBaseline) {
            $matchableDetectors = array_values(array_filter(
                $matchableDetectors,
                static fn (AnomalyDetector $reason): bool => $reason !== AnomalyDetector::Large,
            ));
        }

        if ($matchableDetectors === []) {
            return $reasons;
        }

        $rules = $this->db->connection()->table('anomaly_suppression_rules')
            ->where('user_id', $user->id)
            ->where('direction', $direction)
            ->where('currency', $settledCurrency)
            ->whereIn('detector', array_map(static fn (AnomalyDetector $d): string => $d->value, $matchableDetectors))
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

        $suppressed = AnomalyDetector::listFrom($rules->all());
        if ($suppressed === []) {
            return $reasons;
        }

        return array_values(array_filter(
            $reasons,
            static fn (AnomalyDetector $reason): bool => ! in_array($reason, $suppressed, true),
        ));
    }
}
