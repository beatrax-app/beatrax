<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Anomaly\Public\Events\AnomalyAlertDismissed;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Services\BaseCurrency;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DismissAnomalyAlertAsExpected
{
    private const BAND_LOW_MULTIPLIER = 0.85;

    private const BAND_HIGH_MULTIPLIER = 1.15;

    public function __construct(
        private readonly AnomalyAlertStateMachine $stateMachine,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
        private readonly DatabaseManager $db,
        private readonly BaseCurrency $baseCurrency,
    ) {}

    // Returns TRUE only when a suppression rule was actually written, so the
    // caller does not promise "rule added" when nothing was muted.
    public function __invoke(int $alertId, User $user): bool
    {
        /** @var AnomalyAlert|null $alert */
        $alert = AnomalyAlert::query()
            ->where('id', $alertId)
            ->where('user_id', $user->id)
            ->first();

        if ($alert === null) {
            throw new NotFoundHttpException('Anomaly alert not found.');
        }

        if ($alert->state === AnomalyAlertState::Dismissed->value) {
            return false;
        }

        $now = $this->clock->now();

        $this->stateMachine->transition(
            $alert,
            AnomalyAlertState::Dismissed->value,
            'user_dismissed_expected',
            'user',
            null,
            ['dismissed_as' => 'expected', 'actioned_at' => $now->toDateTimeString()],
        );

        $rulesWritten = $this->insertSuppressionRules($alert, $user, $now->toDateTimeString());

        $this->events->dispatch(new AnomalyAlertDismissed(
            userId: $user->id,
            anomalyAlertId: $alertId,
            dismissedAs: 'expected',
        ));

        return $rulesWritten;
    }

    // The band is derived from the persisted alert, never the client.
    private function insertSuppressionRules(AnomalyAlert $alert, User $user, string $nowString): bool
    {
        $reasons = $alert->reasons;
        if ($reasons === []) {
            return false;
        }

        $currency = is_string($alert->currency) && $alert->currency !== '' ? $alert->currency : null;

        $latestMinor = $alert->latest_amount_minor;
        if ($latestMinor === null) {
            // A duplicate-only / first-time-only alert carries no per-merchant
            // baseline, so the band falls back to the charge's own settled
            // amount — otherwise those detectors could never be suppressed.
            $settled = $this->settledChargeForTransaction($user, $alert->transaction_id);
            if ($settled === null) {
                return false;
            }
            $latestMinor = $settled['amount_minor'];
            $currency ??= $settled['currency'];
        }

        $currency ??= $this->baseCurrency->code();

        $boundA = (int) round(self::BAND_LOW_MULTIPLIER * $latestMinor);
        $boundB = (int) round(self::BAND_HIGH_MULTIPLIER * $latestMinor);
        // For an expense (negative) the 1.15x bound is the more-negative one,
        // so min/max — not the multipliers — decide which end is which.
        $bandLow = min($boundA, $boundB);
        $bandHigh = max($boundA, $boundB);

        $counterpartyId = $this->counterpartyIdForTransaction($user, $alert->transaction_id);

        // Existence-checked rather than a UNIQUE index: SQLite treats NULL
        // counterparty_id values as distinct, so the index would not dedupe
        // the normalized-name fallback rules on re-dismissal.
        $connection = $this->db->connection();
        $wrote = false;

        foreach ($reasons as $reason) {
            $exists = $connection->table('anomaly_suppression_rules')
                ->where('user_id', $user->id)
                ->where('detector', $reason)
                ->where('direction', $alert->direction)
                ->where('amount_band_low_minor', $bandLow)
                ->where('amount_band_high_minor', $bandHigh)
                ->where('currency', $currency)
                ->when(
                    $counterpartyId === null,
                    static fn (Builder $q) => $q->whereNull('counterparty_id'),
                    static fn (Builder $q) => $q->where('counterparty_id', $counterpartyId),
                )
                ->exists();

            if ($exists) {
                continue;
            }

            $connection->table('anomaly_suppression_rules')->insert([
                'user_id' => $user->id,
                'counterparty_id' => $counterpartyId,
                'detector' => $reason,
                'direction' => $alert->direction,
                'amount_band_low_minor' => $bandLow,
                'amount_band_high_minor' => $bandHigh,
                'currency' => $currency,
                'source_anomaly_alert_id' => $alert->id,
                'created_at' => $nowString,
                'updated_at' => $nowString,
            ]);
            $wrote = true;
        }

        return $wrote;
    }

    /**
     * @return array{amount_minor: int, currency: string}|null
     */
    private function settledChargeForTransaction(User $user, int $transactionId): ?array
    {
        $row = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('id', $transactionId)
            ->first(['settled_amount_minor', 'settled_currency']);

        if ($row === null) {
            return null;
        }

        $amount = $row->settled_amount_minor ?? null;
        if (! is_numeric($amount)) {
            return null;
        }

        $currency = is_string($row->settled_currency ?? null) && $row->settled_currency !== ''
            ? $row->settled_currency
            : $this->baseCurrency->code();

        return ['amount_minor' => (int) $amount, 'currency' => $currency];
    }

    // A permitted ledger read (noTransactionWritesFromAnomaly forbids only
    // writes). Null means an unresolved merchant and a counterparty_id IS NULL
    // fallback rule, which the evaluator still matches.
    private function counterpartyIdForTransaction(User $user, int $transactionId): ?int
    {
        $row = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('id', $transactionId)
            ->first(['counterparty_id']);

        if ($row === null) {
            return null;
        }

        $cpId = $row->counterparty_id ?? null;

        return is_numeric($cpId) && (int) $cpId > 0 ? (int) $cpId : null;
    }
}
